<?php

declare(strict_types=1);

namespace App\Tests\Controller\Web;

use App\Benchmark\AuditResult;
use App\Benchmark\BenchmarkResult;
use App\Benchmark\BenchmarkRunner;
use App\Benchmark\ConcurrentPaymentAttemptResult;
use App\Benchmark\MultiUserSimulationResult;
use App\Benchmark\PaymentIntent;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WebInterfaceTest extends WebTestCase
{
    public function testDashboardPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Дашборд спліт-платежів');
        self::assertSelectorExists('nav.navbar');
        self::assertSelectorExists('table, .card');
    }

    public function testCreateUserFromWebForm(): void
    {
        $client = static::createClient();
        $client->request('POST', '/web/users', [
            'name' => 'Web Test User',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-success', 'Web Test User');
    }

    public function testCreateOrderAndProcessPaymentFlow(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // 1. Створюємо користувача
        $user = new User('Payer Web ' . bin2hex(random_bytes(4)));
        $em->persist($user);
        $em->flush();

        // 2. Створюємо замовлення через веб-форму
        $client->request('POST', '/web/orders', [
            'name' => 'Party Pizza Split',
            'full_price_dollars' => '80.00',
            'user_id' => $user->getId(),
        ]);

        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-header', 'Party Pizza Split');
        self::assertSelectorTextContains('.h4', '$80.00');

        // Витягуємо ID замовлення з поточного URL (/web/orders/{id})
        $url = $client->getRequest()->getPathInfo();
        preg_match('#/web/orders/(\d+)#', $url, $matches);
        $orderId = (int) $matches[1];

        // 3. Вносимо валідний платіж через веб-форму
        $idempotencyKey = 'web-test-key-' . bin2hex(random_bytes(6));
        $client->request('POST', sprintf('/web/orders/%d/pay', $orderId), [
            'amount_dollars' => '30.00',
            'user_id' => $user->getId(),
            'payment_method' => 'card',
            'strategy' => 'atomic',
            'idempotency_key' => $idempotencyKey,
        ]);

        self::assertResponseRedirects(sprintf('/web/orders/%d', $orderId));
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-success', '$30.00');
        self::assertSelectorTextContains('table', '$30.00');

        // 4. Повторне надсилання з тим самим idempotency_key повертає конфлікт
        $client->request('POST', sprintf('/web/orders/%d/pay', $orderId), [
            'amount_dollars' => '30.00',
            'user_id' => $user->getId(),
            'payment_method' => 'card',
            'strategy' => 'atomic',
            'idempotency_key' => $idempotencyKey,
        ]);

        self::assertResponseRedirects(sprintf('/web/orders/%d', $orderId));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Конфлікт ідемпотентності');

        // 5. Спроба переплати (сума більша за залишок у $50)
        $client->request('POST', sprintf('/web/orders/%d/pay', $orderId), [
            'amount_dollars' => '100.00',
            'user_id' => $user->getId(),
            'payment_method' => 'card',
            'strategy' => 'atomic',
            'idempotency_key' => 'overpay-key-' . bin2hex(random_bytes(4)),
        ]);

        self::assertResponseRedirects(sprintf('/web/orders/%d', $orderId));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'Оплату відхилено');
    }

    public function testBenchmarkPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/benchmark');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Дослідження стратегій конкурентності');
        self::assertSelectorTextContains('table', 'baseline');
        self::assertSelectorTextContains('table', 'atomic');
    }

    public function testSimulateConcurrentPaymentsRejectsSingleParticipant(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User('Single Sim User ' . bin2hex(random_bytes(4)));
        $order = new Order('Single Sim Order', 5000, $user);
        $em->persist($user);
        $em->persist($order);
        $em->flush();

        $client->disableReboot();
        $runner = $this->createMock(BenchmarkRunner::class);
        $runner->expects(self::never())->method('runConcurrentPayments');
        static::getContainer()->set(BenchmarkRunner::class, $runner);

        $orderId = $order->getId();
        $client->request('POST', sprintf('/web/orders/%d/simulate-concurrent', $orderId), [
            'strategy' => 'atomic',
            'participants' => [
                0 => [
                    'user_id' => (string) $user->getId(),
                    'amount_dollars' => '25.00',
                    'payment_method' => 'card',
                ],
            ],
        ]);

        self::assertResponseRedirects(sprintf('/web/orders/%d', $orderId));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'Додайте щонайменше 2 учасників');
    }

    public function testSimulateConcurrentPaymentsSuccessAndRendersAuditAndAttempts(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = new User('Аліса ' . bin2hex(random_bytes(3)));
        $bob = new User('Боб ' . bin2hex(random_bytes(3)));
        $order = new Order('Multi-User Dinner', 10000, $alice);
        $em->persist($alice);
        $em->persist($bob);
        $em->persist($order);
        $em->flush();

        $orderId = (int) $order->getId();
        $aliceId = (int) $alice->getId();
        $bobId = (int) $bob->getId();

        $client->disableReboot();
        $runner = $this->createMock(BenchmarkRunner::class);
        $runner->expects(self::once())
            ->method('runConcurrentPayments')
            ->with(
                'atomic',
                $orderId,
                self::callback(static function (mixed $intents) use ($aliceId, $bobId): bool {
                    return is_array($intents)
                        && count($intents) === 2
                        && $intents[0] instanceof PaymentIntent
                        && $intents[1] instanceof PaymentIntent
                        && $intents[0]->userId === $aliceId
                        && $intents[0]->paymentMethod === 'card'
                        && $intents[0]->amountCents === 6000
                        && $intents[1]->userId === $bobId
                        && $intents[1]->paymentMethod === 'cash'
                        && $intents[1]->amountCents === 6000;
                }),
                2,
            )
            ->willReturn(new MultiUserSimulationResult(
                strategy: 'atomic',
                orderId: $orderId,
                distinctUserCount: 2,
                benchmark: new BenchmarkResult(
                    strategy: 'atomic',
                    totalRequests: 2,
                    concurrency: 2,
                    totalTimeSeconds: 0.04,
                    requestsPerSecond: 50.0,
                    status201Count: 1,
                    status409Count: 0,
                    status422Count: 1,
                    status5xxCount: 0,
                    latencyMinMs: 8.5,
                    latencyP50Ms: 10.2,
                    latencyP95Ms: 14.7,
                    latencyP99Ms: 14.7,
                    latencyMaxMs: 14.7,
                    audit: new AuditResult(
                        orderId: $orderId,
                        orderFullPriceCents: 10000,
                        orderPaidPriceCents: 6000,
                        actualPaymentSumCents: 6000,
                        paymentCount: 1,
                        isConsistent: true,
                        isOverpaid: false,
                        discrepancyCents: 0,
                    ),
                ),
                attempts: [
                    new ConcurrentPaymentAttemptResult(
                        index: 0,
                        userId: $aliceId,
                        participantLabel: $alice->getName(),
                        amountCents: 6000,
                        paymentMethod: 'card',
                        idempotencyKey: 'sim-key-1',
                        httpStatus: 201,
                        latencyMs: 8.5,
                        paymentId: 101,
                        errorMessage: null,
                    ),
                    new ConcurrentPaymentAttemptResult(
                        index: 1,
                        userId: $bobId,
                        participantLabel: $bob->getName(),
                        amountCents: 6000,
                        paymentMethod: 'cash',
                        idempotencyKey: 'sim-key-2',
                        httpStatus: 422,
                        latencyMs: 14.7,
                        paymentId: null,
                        errorMessage: 'Overpayment exceeds remaining order balance.',
                    ),
                ],
            ));

        static::getContainer()->set(BenchmarkRunner::class, $runner);

        $client->request('POST', sprintf('/web/orders/%d/simulate-concurrent', $orderId), [
            'strategy' => 'atomic',
            'concurrency' => '',
            'participants' => [
                0 => [
                    'user_id' => (string) $aliceId,
                    'amount_dollars' => '60.00',
                    'payment_method' => 'card',
                ],
                3 => [
                    'user_id' => (string) $bobId,
                    'amount_dollars' => '60.00',
                    'payment_method' => 'cash',
                ],
            ],
        ]);

        self::assertResponseRedirects(sprintf('/web/orders/%d', $orderId));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#simulationResultsPanel');
        self::assertSelectorTextContains('.alert-success', 'інваріанти I1–I5 збережено');
        self::assertSelectorTextContains('#simulationResultsPanel', '201 Created');
        self::assertSelectorTextContains('#simulationResultsPanel', '422 Unprocessable');
        self::assertSelectorTextContains('#simulationResultsPanel', $alice->getName());
        self::assertSelectorTextContains('#simulationResultsPanel', $bob->getName());
        self::assertSelectorTextContains('#simulationResultsPanel', 'ЗБЕРЕЖЕНО');
    }

    public function testSimulateConcurrentPaymentsShowsViolationWhenBaselineOverpays(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $userA = new User('Олег ' . bin2hex(random_bytes(3)));
        $userB = new User('Ірина ' . bin2hex(random_bytes(3)));
        $order = new Order('Baseline Race Order', 3000, $userA);
        $em->persist($userA);
        $em->persist($userB);
        $em->persist($order);
        $em->flush();

        $orderId = (int) $order->getId();
        $userAId = (int) $userA->getId();
        $userBId = (int) $userB->getId();

        $client->disableReboot();
        $runner = $this->createMock(BenchmarkRunner::class);
        $runner->expects(self::once())
            ->method('runConcurrentPayments')
            ->willReturn(new MultiUserSimulationResult(
                strategy: 'baseline',
                orderId: $orderId,
                distinctUserCount: 2,
                benchmark: new BenchmarkResult(
                    strategy: 'baseline',
                    totalRequests: 2,
                    concurrency: 2,
                    totalTimeSeconds: 0.03,
                    requestsPerSecond: 66.7,
                    status201Count: 2,
                    status409Count: 0,
                    status422Count: 0,
                    status5xxCount: 0,
                    latencyMinMs: 7.0,
                    latencyP50Ms: 9.0,
                    latencyP95Ms: 11.0,
                    latencyP99Ms: 11.0,
                    latencyMaxMs: 11.0,
                    audit: new AuditResult(
                        orderId: $orderId,
                        orderFullPriceCents: 3000,
                        orderPaidPriceCents: 2000,
                        actualPaymentSumCents: 4000,
                        paymentCount: 2,
                        isConsistent: false,
                        isOverpaid: true,
                        discrepancyCents: 2000,
                    ),
                ),
                attempts: [
                    new ConcurrentPaymentAttemptResult(
                        index: 0,
                        userId: $userAId,
                        participantLabel: $userA->getName(),
                        amountCents: 2000,
                        paymentMethod: 'card',
                        idempotencyKey: 'race-1',
                        httpStatus: 201,
                        latencyMs: 7.0,
                        paymentId: 201,
                    ),
                    new ConcurrentPaymentAttemptResult(
                        index: 1,
                        userId: $userBId,
                        participantLabel: $userB->getName(),
                        amountCents: 2000,
                        paymentMethod: 'cash',
                        idempotencyKey: 'race-2',
                        httpStatus: 201,
                        latencyMs: 11.0,
                        paymentId: 202,
                    ),
                ],
            ));

        static::getContainer()->set(BenchmarkRunner::class, $runner);

        $client->request('POST', sprintf('/web/orders/%d/simulate-concurrent', $orderId), [
            'strategy' => 'baseline',
            'concurrency' => '2',
            'participants' => [
                0 => [
                    'user_id' => (string) $userAId,
                    'amount_dollars' => '20.00',
                    'payment_method' => 'card',
                ],
                1 => [
                    'user_id' => (string) $userBId,
                    'amount_dollars' => '20.00',
                    'payment_method' => 'cash',
                ],
            ],
        ]);

        self::assertResponseRedirects(sprintf('/web/orders/%d', $orderId));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#simulationResultsPanel');
        self::assertSelectorTextContains('.alert-warning', 'відхиленнями');
        self::assertSelectorTextContains('#simulationResultsPanel', 'ПОРУШЕНО');
    }

    public function testMultiUserWebBenchmarkPostDelegatesToRunConcurrentPayments(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $runner = $this->createMock(BenchmarkRunner::class);
        $runner->expects(self::once())
            ->method('runConcurrentPayments')
            ->with(
                'atomic',
                self::callback(static fn (mixed $orderId): bool => is_int($orderId) && $orderId > 0),
                self::callback(static function (mixed $intents): bool {
                    if (!is_array($intents) || count($intents) !== 6) {
                        return false;
                    }
                    $userIds = [];
                    foreach ($intents as $intent) {
                        if (!$intent instanceof PaymentIntent) {
                            return false;
                        }
                        $userIds[$intent->userId] = true;
                    }

                    return count($userIds) === 3
                        && $intents[0]->paymentMethod === 'card'
                        && $intents[1]->paymentMethod === 'cash';
                }),
                3,
            )
            ->willReturnCallback(static fn (string $strategy, int $orderId, array $intents, int $concurrency): MultiUserSimulationResult => new MultiUserSimulationResult(
                strategy: $strategy,
                orderId: $orderId,
                distinctUserCount: 3,
                benchmark: new BenchmarkResult(
                    strategy: $strategy,
                    totalRequests: count($intents),
                    concurrency: $concurrency,
                    totalTimeSeconds: 0.04,
                    requestsPerSecond: 150.0,
                    status201Count: 6,
                    status409Count: 0,
                    status422Count: 0,
                    status5xxCount: 0,
                    latencyMinMs: 5.0,
                    latencyP50Ms: 8.5,
                    latencyP95Ms: 14.0,
                    latencyP99Ms: 14.0,
                    latencyMaxMs: 14.0,
                    audit: new AuditResult($orderId, 10000, 6000, 6000, 6, true, false, 0),
                ),
                attempts: [],
            ));

        static::getContainer()->set(BenchmarkRunner::class, $runner);

        $client->request('POST', '/benchmark/run', [
            'strategy' => 'atomic',
            'users_count' => '3',
            'total_requests' => '6',
            'concurrency' => '3',
            'payment_amount_dollars' => '10.00',
            'order_price_dollars' => '100.00',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-success', '3 учасників');
        self::assertSelectorTextContains('.card-header', '3 учасник(ів)');
        self::assertSelectorTextContains('.card', '150.0');
        self::assertSelectorTextContains('.card', 'ЗБЕРЕЖЕНО');
    }
}
