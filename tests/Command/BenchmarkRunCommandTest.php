<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Benchmark\AuditResult;
use App\Benchmark\BenchmarkResult;
use App\Benchmark\BenchmarkRunner;
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
}
