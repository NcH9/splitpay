<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Benchmark\BenchmarkResult;
use App\Benchmark\BenchmarkRunner;
use App\Benchmark\PaymentIntent;
use App\DTO\Order\CreateOrderRequest;
use App\DTO\User\CreateUserRequest;
use App\Repository\UserRepository;
use App\Service\OrderService;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WebBenchmarkController extends AbstractController
{
    public function __construct(
        private readonly BenchmarkRunner $benchmarkRunner,
        private readonly OrderService $orderService,
        private readonly UserService $userService,
        private readonly UserRepository $userRepository,
    ) {}

    #[Route('/benchmark', name: 'web_benchmark', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('benchmark/index.html.twig', [
            'benchmarkResult' => null,
            'testOrder' => null,
            'usersCount' => 1,
        ]);
    }

    #[Route('/benchmark/run', name: 'web_benchmark_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $strategy = (string) $request->request->get('strategy', 'atomic');
        $usersCount = max(1, min(50, (int) $request->request->get('users_count', 1)));
        $totalRequests = max(1, min(500, (int) $request->request->get('total_requests', 100)));
        $concurrency = max(1, min(50, (int) $request->request->get('concurrency', 10)));
        $paymentAmountDollars = (float) $request->request->get('payment_amount_dollars', 10.00);
        $orderPriceDollars = (float) $request->request->get('order_price_dollars', 100.00);

        $paymentAmountCents = (int) round($paymentAmountDollars * 100);
        $orderPriceCents = (int) round($orderPriceDollars * 100);

        if ($usersCount === 1) {
            // Отримуємо або створюємо бенчмарк-користувача
            $user = $this->userRepository->findOneBy(['name' => 'Benchmark Worker']);
            if ($user === null) {
                $user = $this->userService->create(new CreateUserRequest('Benchmark Worker'));
            }

            // Створюємо виділене замовлення під навантажувальний тест
            $orderTitle = sprintf('Benchmark [%s] %s', strtoupper($strategy), (new \DateTimeImmutable())->format('H:i:s'));
            $order = $this->orderService->create(new CreateOrderRequest($orderTitle, $orderPriceCents, $user->getId()));

            $runCallback = fn (): BenchmarkResult => $this->benchmarkRunner->run(
                strategy: $strategy,
                orderId: $order->getId(),
                userId: $user->getId(),
                totalRequests: $totalRequests,
                concurrency: $concurrency,
                paymentAmountCents: $paymentAmountCents,
            );
        } else {
            $workerIds = [];
            for ($u = 1; $u <= $usersCount; ++$u) {
                $workerName = 'Benchmark Worker #' . $u;
                $worker = $this->userRepository->findOneBy(['name' => $workerName]);
                if ($worker === null) {
                    $worker = $this->userService->create(new CreateUserRequest($workerName));
                }
                $workerIds[] = $worker->getId();
            }

            $orderTitle = sprintf('Benchmark [%s, %d users] %s', strtoupper($strategy), $usersCount, (new \DateTimeImmutable())->format('H:i:s'));
            $order = $this->orderService->create(new CreateOrderRequest($orderTitle, $orderPriceCents, $workerIds[0]));

            $intents = [];
            for ($i = 0; $i < $totalRequests; ++$i) {
                $intents[] = new PaymentIntent(
                    userId: $workerIds[$i % $usersCount],
                    amountCents: $paymentAmountCents,
                    paymentMethod: $i % 2 === 0 ? 'card' : 'cash',
                );
            }

            $runCallback = fn (): BenchmarkResult => $this->benchmarkRunner->runConcurrentPayments(
                strategy: $strategy,
                orderId: $order->getId(),
                intents: $intents,
                concurrency: $concurrency,
            )->benchmark;
        }

        try {
            $benchmarkResult = $runCallback();

            // Оновлюємо сутність замовлення для відображення фінального стану
            $refreshedOrder = $this->orderService->getOrderById($order->getId());

            if ($usersCount > 1) {
                $this->addFlash('success', sprintf(
                    'Бенчмарк стратегії <code>%s</code> (%d учасників) завершено: <strong>%d</strong> запитів оброблено за <strong>%.2f с</strong> (RPS: <strong>%.2f</strong>).',
                    htmlspecialchars($strategy, ENT_QUOTES, 'UTF-8'),
                    $usersCount,
                    $totalRequests,
                    $benchmarkResult->totalTimeSeconds,
                    $benchmarkResult->requestsPerSecond,
                ));
            } else {
                $this->addFlash('success', sprintf(
                    'Бенчмарк стратегії <code>%s</code> завершено: <strong>%d</strong> запитів оброблено за <strong>%.2f с</strong> (RPS: <strong>%.2f</strong>).',
                    htmlspecialchars($strategy, ENT_QUOTES, 'UTF-8'),
                    $totalRequests,
                    $benchmarkResult->totalTimeSeconds,
                    $benchmarkResult->requestsPerSecond,
                ));
            }

            return $this->render('benchmark/index.html.twig', [
                'benchmarkResult' => $benchmarkResult,
                'testOrder' => $refreshedOrder,
                'usersCount' => $usersCount,
                'lastParams' => [
                    'strategy' => $strategy,
                    'usersCount' => $usersCount,
                    'totalRequests' => $totalRequests,
                    'concurrency' => $concurrency,
                    'paymentAmountDollars' => $paymentAmountDollars,
                    'orderPriceDollars' => $orderPriceDollars,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Помилка під час виконання бенчмарку: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));

            return $this->redirectToRoute('web_benchmark');
        }
    }
}
