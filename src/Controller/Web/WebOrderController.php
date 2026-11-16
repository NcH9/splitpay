<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Benchmark\BenchmarkRunner;
use App\Benchmark\ConcurrentPaymentAttemptResult;
use App\Benchmark\PaymentIntent;
use App\DTO\Order\CreateOrderRequest;
use App\DTO\Payment\ProcessPaymentRequest;
use App\Exception\IdempotencyConflictException;
use App\Exception\OptimisticLockExhaustedException;
use App\Exception\OrderNotOpenException;
use App\Exception\OverpaymentException;
use App\Payment\StrategyRegistry;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use App\Service\OrderService;
use App\Service\PaymentService;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class WebOrderController extends AbstractController
{
    private const SIMULATION_SESSION_KEY_PREFIX = 'splitpay_order_simulation_';
    private const ALLOWED_STRATEGIES = ['atomic', 'pessimistic', 'optimistic', 'mutex', 'baseline'];

    public function __construct(
        private readonly OrderService $orderService,
        private readonly UserService $userService,
        private readonly PaymentService $paymentService,
        private readonly UserRepository $userRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly StrategyRegistry $strategyRegistry,
        private readonly BenchmarkRunner $benchmarkRunner,
    ) {}

    #[Route('/web/orders', name: 'web_order_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $name = trim((string) $request->request->get('name'));
        $dollars = (float) $request->request->get('full_price_dollars', 0);
        $userId = (int) $request->request->get('user_id');

        $cents = (int) round($dollars * 100);

        if ($name === '') {
            $this->addFlash('error', 'Назва замовлення не може бути порожньою.');
            return $this->redirect($request->headers->get('referer', $this->generateUrl('web_dashboard')));
        }

        if ($cents <= 0) {
            $this->addFlash('error', 'Сума замовлення має бути більшою за 0.');
            return $this->redirect($request->headers->get('referer', $this->generateUrl('web_dashboard')));
        }

        if ($userId <= 0) {
            $this->addFlash('error', 'Вкажіть коректний ID користувача.');
            return $this->redirect($request->headers->get('referer', $this->generateUrl('web_dashboard')));
        }

        try {
            $order = $this->orderService->create(new CreateOrderRequest($name, $cents, $userId));
            $this->addFlash('success', sprintf('Замовлення <strong>"%s"</strong> #%d на суму $%s успішно створено.', htmlspecialchars($order->getName(), ENT_QUOTES, 'UTF-8'), $order->getId(), number_format($order->getFullPriceCents() / 100, 2)));

            return $this->redirectToRoute('web_order_show', ['id' => $order->getId()]);
        } catch (NotFoundHttpException $e) {
            $this->addFlash('error', sprintf('Користувача #%d не знайдено. Спочатку створіть користувача.', $userId));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Помилка під час створення замовлення: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $this->redirect($request->headers->get('referer', $this->generateUrl('web_dashboard')));
    }

    #[Route('/web/orders/{id}', name: 'web_order_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request): Response
    {
        $order = $this->orderService->getOrderById($id);

        if ($order === null) {
            throw $this->createNotFoundException(sprintf('Замовлення #%d не знайдено.', $id));
        }

        $simulationResult = null;
        if ($request->hasSession()) {
            $sessionKey = self::SIMULATION_SESSION_KEY_PREFIX . $id;
            $simulationResult = $request->getSession()->get($sessionKey);
            $request->getSession()->remove($sessionKey);
        }

        $users = $this->userRepository->findBy([], ['id' => 'ASC']);
        $payments = $this->paymentRepository->findBy(['order' => $order], ['id' => 'DESC']);

        $strategies = [
            'atomic' => 'Atomic Conditional UPDATE (Найвища швидкість, захист інваріантів I1-I5)',
            'pessimistic' => 'Pessimistic Lock (SELECT ... FOR UPDATE)',
            'optimistic' => 'Optimistic Lock (ORM Version + Retry)',
            'mutex' => 'Symfony Lock (М\'ютекс рівня застосунку)',
            'baseline' => 'Baseline (Контрольна група — без блокувань, вразлива до Race Condition)',
        ];

        return $this->render('order/show.html.twig', [
            'order' => $order,
            'users' => $users,
            'payments' => $payments,
            'strategies' => $strategies,
            'defaultIdempotencyKey' => 'splitpay-' . bin2hex(random_bytes(8)),
            'simulationResult' => $simulationResult,
        ]);
    }

    #[Route('/web/orders/{id}/simulate-concurrent', name: 'web_order_simulate_concurrent', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function simulateConcurrent(int $id, Request $request): Response
    {
        $order = $this->orderService->getOrderById($id);
        if ($order === null) {
            throw $this->createNotFoundException(sprintf('Замовлення #%d не знайдено.', $id));
        }

        $strategy = (string) $request->request->get('strategy', 'atomic');
        if (!in_array($strategy, self::ALLOWED_STRATEGIES, true)) {
            $this->addFlash('error', 'Обрано невідому стратегію синхронізації.');

            return $this->redirectToRoute('web_order_show', ['id' => $id]);
        }

        try {
            $rawParticipants = $request->request->all('participants');
        } catch (BadRequestException) {
            $rawParticipants = [];
        }

        if (!is_array($rawParticipants) || $rawParticipants === []) {
            $this->addFlash('error', 'Додайте щонайменше 2 учасників для симуляції конкурентної оплати.');

            return $this->redirectToRoute('web_order_show', ['id' => $id]);
        }

        $users = $this->userRepository->findBy([], ['id' => 'ASC']);
        $usersById = [];
        foreach ($users as $user) {
            $userId = $user->getId();
            if ($userId !== null) {
                $usersById[$userId] = $user;
            }
        }

        $intents = [];
        foreach ($rawParticipants as $row) {
            if (!is_array($row)) {
                $this->addFlash('error', 'Некоректний формат даних учасника.');

                return $this->redirectToRoute('web_order_show', ['id' => $id]);
            }

            $userId = filter_var($row['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($userId) || $userId <= 0 || !isset($usersById[$userId])) {
                $this->addFlash('error', 'Один із вибраних учасників не існує. Перевірте список платників.');

                return $this->redirectToRoute('web_order_show', ['id' => $id]);
            }

            $rawDollars = $row['amount_dollars'] ?? null;
            if (!is_numeric($rawDollars)) {
                $this->addFlash('error', 'Сума платежу для кожного учасника має бути додатним числом.');

                return $this->redirectToRoute('web_order_show', ['id' => $id]);
            }

            $amountCents = (int) round((float) $rawDollars * 100);
            if ($amountCents <= 0) {
                $this->addFlash('error', 'Сума платежу для кожного учасника має бути більшою за $0.00.');

                return $this->redirectToRoute('web_order_show', ['id' => $id]);
            }

            $method = (string) ($row['payment_method'] ?? 'card');
            if ($method === '') {
                $method = 'card';
            }
            if (!in_array($method, ['card', 'cash'], true)) {
                $this->addFlash('error', 'Спосіб оплати для кожного учасника має бути "card" або "cash".');

                return $this->redirectToRoute('web_order_show', ['id' => $id]);
            }

            $intents[] = new PaymentIntent(
                userId: $userId,
                amountCents: $amountCents,
                paymentMethod: $method,
                participantLabel: $usersById[$userId]->getName(),
            );
        }

        if (count($intents) < 2) {
            $this->addFlash('error', 'Додайте щонайменше 2 учасників для симуляції конкурентної оплати.');

            return $this->redirectToRoute('web_order_show', ['id' => $id]);
        }

        $rawConcurrency = $request->request->get('concurrency');
        $concurrencyValue = ($rawConcurrency === null || trim((string) $rawConcurrency) === '')
            ? count($intents)
            : (int) $rawConcurrency;
        $concurrency = max(1, min(50, $concurrencyValue));

        try {
            $simulation = $this->benchmarkRunner->runConcurrentPayments($strategy, $id, $intents, $concurrency);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Помилка під час симуляції конкурентної оплати: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));

            return $this->redirectToRoute('web_order_show', ['id' => $id]);
        }

        $audit = $simulation->benchmark->audit;
        $benchmark = $simulation->benchmark;

        $request->getSession()->set(self::SIMULATION_SESSION_KEY_PREFIX . $id, [
            'strategy' => $simulation->strategy,
            'distinctUserCount' => $simulation->distinctUserCount,
            'totalRequests' => $benchmark->totalRequests,
            'concurrency' => $benchmark->concurrency,
            'requestsPerSecond' => $benchmark->requestsPerSecond,
            'totalTimeSeconds' => $benchmark->totalTimeSeconds,
            'latencyP50Ms' => $benchmark->latencyP50Ms,
            'latencyP95Ms' => $benchmark->latencyP95Ms,
            'status201Count' => $benchmark->status201Count,
            'status409Count' => $benchmark->status409Count,
            'status422Count' => $benchmark->status422Count,
            'status5xxCount' => $benchmark->status5xxCount,
            'audit' => [
                'orderFullPriceCents' => $audit->orderFullPriceCents,
                'orderPaidPriceCents' => $audit->orderPaidPriceCents,
                'actualPaymentSumCents' => $audit->actualPaymentSumCents,
                'paymentCount' => $audit->paymentCount,
                'isConsistent' => $audit->isConsistent,
                'isOverpaid' => $audit->isOverpaid,
                'discrepancyCents' => $audit->discrepancyCents,
            ],
            'attempts' => array_map(
                static fn (ConcurrentPaymentAttemptResult $a): array => [
                    'index' => $a->index,
                    'userId' => $a->userId,
                    'participantLabel' => $a->participantLabel,
                    'amountCents' => $a->amountCents,
                    'paymentMethod' => $a->paymentMethod,
                    'idempotencyKey' => $a->idempotencyKey,
                    'httpStatus' => $a->httpStatus,
                    'latencyMs' => $a->latencyMs,
                    'paymentId' => $a->paymentId,
                    'errorMessage' => $a->errorMessage,
                ],
                $simulation->attempts,
            ),
        ]);

        if (!$audit->isOverpaid && $audit->isConsistent && $benchmark->status5xxCount === 0) {
            $this->addFlash('success', sprintf(
                'Симуляцію (%s) завершено: %d учасників, %d успішних платежів (201 Created), інваріанти I1–I5 збережено.',
                htmlspecialchars($simulation->strategy, ENT_QUOTES, 'UTF-8'),
                $simulation->distinctUserCount,
                $benchmark->status201Count,
            ));
        } else {
            $this->addFlash('warning', sprintf(
                'Симуляцію (%s) завершено з відхиленнями: 201=%d, 5xx=%d, переплата=%s, консистентність=%s.',
                htmlspecialchars($simulation->strategy, ENT_QUOTES, 'UTF-8'),
                $benchmark->status201Count,
                $benchmark->status5xxCount,
                $audit->isOverpaid ? 'ТАК' : 'НІ',
                $audit->isConsistent ? 'ТАК' : 'НІ',
            ));
        }

        return $this->redirectToRoute('web_order_show', ['id' => $id]);
    }

    #[Route('/web/orders/{id}/pay', name: 'web_order_pay', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pay(int $id, Request $request): Response
    {
        $dollars = (float) $request->request->get('amount_dollars', 0);
        $amountCents = (int) round($dollars * 100);
        $userId = (int) $request->request->get('user_id');
        $paymentMethod = (string) $request->request->get('payment_method', 'card');
        $strategy = (string) $request->request->get('strategy', 'atomic');
        $idempotencyKey = trim((string) $request->request->get('idempotency_key'));

        if ($idempotencyKey === '') {
            $idempotencyKey = 'splitpay-' . bin2hex(random_bytes(8));
        }

        if ($amountCents <= 0) {
            $this->addFlash('error', 'Сума платежу має бути більшою за 0.');
            return $this->redirectToRoute('web_order_show', ['id' => $id]);
        }

        if ($userId <= 0) {
            $this->addFlash('error', 'Оберіть користувача для проведення оплати.');
            return $this->redirectToRoute('web_order_show', ['id' => $id]);
        }

        try {
            $dto = new ProcessPaymentRequest(
                amountCents: $amountCents,
                paymentMethod: $paymentMethod,
                idempotencyKey: $idempotencyKey,
                userId: $userId,
                strategy: $strategy,
            );

            $result = $this->paymentService->pay($id, $dto);

            $this->addFlash('success', sprintf(
                'Платіж <strong>#%d</strong> на суму <strong>$%s</strong> успішно проведено! Стратегія: <code>%s</code>%s. Idempotency key: <code>%s</code>',
                $result->payment->getId(),
                number_format($amountCents / 100, 2),
                htmlspecialchars($result->strategyName, ENT_QUOTES, 'UTF-8'),
                $result->retriesAttempted > 0 ? " (повторів: {$result->retriesAttempted})" : '',
                htmlspecialchars($idempotencyKey, ENT_QUOTES, 'UTF-8'),
            ));
        } catch (OverpaymentException $e) {
            $this->addFlash('error', sprintf(
                '<i class="bi bi-shield-x me-2"></i><strong>[OverpaymentException] Оплату відхилено!</strong> %s',
                htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            ));
        } catch (IdempotencyConflictException $e) {
            $this->addFlash('warning', sprintf(
                '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>[IdempotencyConflictException] Конфлікт ідемпотентності!</strong> Платіж із ключем <code>%s</code> уже було успішно зареєстровано раніше.',
                htmlspecialchars($idempotencyKey, ENT_QUOTES, 'UTF-8'),
            ));
        } catch (OrderNotOpenException $e) {
            $this->addFlash('info', sprintf(
                '<i class="bi bi-info-circle-fill me-2"></i><strong>[OrderNotOpenException] Замовлення закрите!</strong> Це замовлення вже повністю оплачене (статус PAID), нові платежі не приймаються.',
            ));
        } catch (OptimisticLockExhaustedException $e) {
            $this->addFlash('warning', sprintf(
                '<i class="bi bi-arrow-repeat me-2"></i><strong>[OptimisticLockExhaustedException] Конфлікт версії!</strong> Вичерпано спроби оновлення через паралельні транзакції: %s',
                htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            ));
        } catch (NotFoundHttpException $e) {
            $this->addFlash('error', htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Непередбачена помилка: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $this->redirectToRoute('web_order_show', ['id' => $id]);
    }
}
