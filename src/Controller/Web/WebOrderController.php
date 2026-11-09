<?php

declare(strict_types=1);

namespace App\Controller\Web;

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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class WebOrderController extends AbstractController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly UserService $userService,
        private readonly PaymentService $paymentService,
        private readonly UserRepository $userRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly StrategyRegistry $strategyRegistry,
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
    public function show(int $id): Response
    {
        $order = $this->orderService->getOrderById($id);

        if ($order === null) {
            throw $this->createNotFoundException(sprintf('Замовлення #%d не знайдено.', $id));
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
        ]);
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
