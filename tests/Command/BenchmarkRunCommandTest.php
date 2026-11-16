<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Benchmark\AuditResult;
use App\Benchmark\BenchmarkResult;
use App\Benchmark\BenchmarkRunner;
use App\Benchmark\ConcurrentPaymentAttemptResult;
use App\Benchmark\MultiUserSimulationResult;
use App\Benchmark\PaymentIntent;
use App\Command\BenchmarkRunCommand;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BenchmarkRunCommand::class)]
final class BenchmarkRunCommandTest extends KernelTestCase
{
    public function testRunsAndFormatsBenchmarkAgainstPersistedScenario(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $runner = $this->createMock(BenchmarkRunner::class);
        $runner->expects(self::once())
            ->method('run')
            ->with('baseline', self::callback(static fn (mixed $value): bool => is_int($value)), self::callback(static fn (mixed $value): bool => is_int($value)), 1, 1, 1000)
            ->willReturn(new BenchmarkResult(
                strategy: 'baseline',
                totalRequests: 1,
                concurrency: 1,
                totalTimeSeconds: 0.01,
                requestsPerSecond: 100.0,
                status201Count: 1,
                status409Count: 0,
                status422Count: 0,
                status5xxCount: 0,
                latencyMinMs: 10.0,
                latencyP50Ms: 10.0,
                latencyP95Ms: 10.0,
                latencyP99Ms: 10.0,
                latencyMaxMs: 10.0,
                audit: new AuditResult(1, 10000, 1000, 1000, 1, true, false, 0),
            ));

        $command = new BenchmarkRunCommand($container->get(EntityManagerInterface::class), $runner);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--strategy' => 'baseline',
            '--requests' => '1',
            '--concurrency' => '1',
            '--payment-amount' => '1000',
            '--format' => 'markdown',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('| baseline |', $tester->getDisplay());
        self::assertStringContainsString('Paid / Actual', $tester->getDisplay());
    }

    public function testRejectsInvalidFormat(): void
    {
        self::bootKernel();
        $command = new BenchmarkRunCommand(
            static::getContainer()->get(EntityManagerInterface::class),
            static::createStub(BenchmarkRunner::class),
        );
        $tester = new CommandTester($command);

        self::assertSame(2, $tester->execute(['--format' => 'xml']));
    }

    public function testRunsMultiUserBenchmarkWithRoundRobinAndMixedMethods(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $capturedIntents = [];
        $runner = $this->createMock(BenchmarkRunner::class);
        $runner->expects(self::once())
            ->method('runConcurrentPayments')
            ->with(
                'atomic',
                self::callback(static fn (mixed $orderId): bool => is_int($orderId) && $orderId > 0),
                self::callback(static function (mixed $intents) use (&$capturedIntents): bool {
                    if (!is_array($intents) || count($intents) !== 6) {
                        return false;
                    }
                    foreach ($intents as $intent) {
                        if (!$intent instanceof PaymentIntent) {
                            return false;
                        }
                    }
                    $capturedIntents = $intents;

                    return true;
                }),
                3,
            )
            ->willReturnCallback(static function (string $strategy, int $orderId, array $intents, int $concurrency): MultiUserSimulationResult {
                return new MultiUserSimulationResult(
                    strategy: $strategy,
                    orderId: $orderId,
                    distinctUserCount: 4,
                    benchmark: new BenchmarkResult(
                        strategy: $strategy,
                        totalRequests: count($intents),
                        concurrency: $concurrency,
                        totalTimeSeconds: 0.05,
                        requestsPerSecond: 120.0,
                        status201Count: 6,
                        status409Count: 0,
                        status422Count: 0,
                        status5xxCount: 0,
                        latencyMinMs: 5.0,
                        latencyP50Ms: 10.0,
                        latencyP95Ms: 15.0,
                        latencyP99Ms: 18.0,
                        latencyMaxMs: 20.0,
                        audit: new AuditResult($orderId, 10000, 6000, 6000, 6, true, false, 0),
                    ),
                    attempts: array_map(
                        static fn (int $idx, PaymentIntent $intent): ConcurrentPaymentAttemptResult => new ConcurrentPaymentAttemptResult(
                            index: $idx,
                            userId: $intent->userId,
                            participantLabel: $intent->participantLabel,
                            amountCents: $intent->amountCents,
                            paymentMethod: $intent->paymentMethod,
                            idempotencyKey: 'key-' . $idx,
                            httpStatus: 201,
                            latencyMs: 10.0,
                            paymentId: $idx + 1,
                        ),
                        array_keys($intents),
                        $intents,
                    ),
                );
            });

        $command = new BenchmarkRunCommand($container->get(EntityManagerInterface::class), $runner);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--users' => '4',
            '--requests' => '6',
            '--concurrency' => '3',
            '--strategy' => 'atomic',
            '--payment-amount' => '1000',
            '--format' => 'markdown',
        ]);

        self::assertSame(0, $exitCode);
        self::assertCount(6, $capturedIntents);

        $uniqueUsers = array_unique(array_map(static fn (PaymentIntent $intent): int => $intent->userId, $capturedIntents));
        self::assertCount(4, $uniqueUsers);

        $methods = array_map(static fn (PaymentIntent $intent): string => $intent->paymentMethod, $capturedIntents);
        self::assertSame(['card', 'cash', 'card', 'cash', 'card', 'cash'], $methods);
        foreach ($capturedIntents as $intent) {
            self::assertSame(1000, $intent->amountCents);
        }

        $display = $tester->getDisplay();
        self::assertStringContainsString('| atomic |', $display);
        self::assertStringContainsString('| Users |', $display);
        self::assertStringContainsString('| 4 |', $display);
        self::assertStringContainsString('Paid / Actual', $display);
    }

    public function testRejectsInvalidUsersOption(): void
    {
        self::bootKernel();
        $command = new BenchmarkRunCommand(
            static::getContainer()->get(EntityManagerInterface::class),
            static::createStub(BenchmarkRunner::class),
        );
        $tester = new CommandTester($command);

        self::assertSame(2, $tester->execute(['--users' => '0']));
    }
}
