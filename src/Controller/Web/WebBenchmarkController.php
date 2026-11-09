<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Benchmark\BenchmarkRunner;
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
        ]);
    }

    #[Route('/benchmark/run', name: 'web_benchmark_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $strategy = (string) $request->request->get('strategy', 'atomic');
        $totalRequests = max(1, min(500, (int) $request->request->get('total_requests', 100)));
        $concurrency = max(1, min(50, (int) $request->request->get('concurrency', 10)));
        $paymentAmountDollars = (float) $request->request->get('payment_amount_dollars', 10.00);
        $orderPriceDollars = (float) $request->request->get('order_price_dollars', 100.00);

        $paymentAmountCents = (int) round($paymentAmountDollars * 100);
        $orderPriceCents = (int) round($orderPriceDollars * 100);

        // Отримуємо або створюємо бенчмарк-користувача
        $user = $this->userRepository->findOneBy(['name' => 'Benchmark Worker']);
        if ($user === null) {
            $user = $this->userService->create(new CreateUserRequest('Benchmark Worker'));
        }

        // Створюємо виділене замовлення під навантажувальний тест
        $orderTitle = sprintf('Benchmark [%s] %s', strtoupper($strategy), (new \DateTimeImmutable())->format('H:i:s'));
        $order = $this->orderService->create(new CreateOrderRequest($orderTitle, $orderPriceCents, $user->getId()));

        try {
            $benchmarkResult = $this->benchmarkRunner->run(
                strategy: $strategy,
                orderId: $order->getId(),
                userId: $user->getId(),
                totalRequests: $totalRequests,
                concurrency: $concurrency,
                paymentAmountCents: $paymentAmountCents,
            );

            // Оновлюємо сутність замовлення для відображення фінального стану
            $refreshedOrder = $this->orderService->getOrderById($order->getId());

            $this->addFlash('success', sprintf(
                'Бенчмарк стратегії <code>%s</code> завершено: <strong>%d</strong> запитів оброблено за <strong>%.2f с</strong> (RPS: <strong>%.2f</strong>).',
                htmlspecialchars($strategy, ENT_QUOTES, 'UTF-8'),
                $totalRequests,
                $benchmarkResult->totalTimeSeconds,
                $benchmarkResult->requestsPerSecond,
            ));

            return $this->render('benchmark/index.html.twig', [
                'benchmarkResult' => $benchmarkResult,
                'testOrder' => $refreshedOrder,
                'lastParams' => [
                    'strategy' => $strategy,
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
