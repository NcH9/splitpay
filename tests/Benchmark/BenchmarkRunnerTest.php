<?php

declare(strict_types=1);

namespace App\Tests\Benchmark;

use App\Benchmark\BenchmarkRunner;
use App\Benchmark\ConsistencyAuditor;
use App\Benchmark\PaymentIntent;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class BenchmarkRunnerTest extends TestCase
{
    private function createRunner(): BenchmarkRunner
    {
        return new BenchmarkRunner(new ConsistencyAuditor(static::createStub(Connection::class)));
    }

    public function testRunConcurrentPaymentsThrowsOnEmptyIntents(): void
    {
        $runner = $this->createRunner();

        $this->expectException(\InvalidArgumentException::class);
        $runner->runConcurrentPayments('atomic', 1, [], 2);
    }

    public function testRunConcurrentPaymentsThrowsOnNonPositiveConcurrency(): void
    {
        $runner = $this->createRunner();

        $this->expectException(\InvalidArgumentException::class);
        $runner->runConcurrentPayments('atomic', 1, [new PaymentIntent(1, 1000)], 0);
    }

    public function testRunConcurrentPaymentsThrowsOnUnknownStrategy(): void
    {
        $runner = $this->createRunner();

        $this->expectException(\InvalidArgumentException::class);
        $runner->runConcurrentPayments('unknown', 1, [new PaymentIntent(1, 1000)], 2);
    }

    public function testRunConcurrentPaymentsThrowsOnNonPositiveOrderId(): void
    {
        $runner = $this->createRunner();

        $this->expectException(\InvalidArgumentException::class);
        $runner->runConcurrentPayments('atomic', 0, [new PaymentIntent(1, 1000)], 2);
    }

    public function testRunConcurrentPaymentsThrowsOnInvalidIntentElement(): void
    {
        $runner = $this->createRunner();

        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line */
        $runner->runConcurrentPayments('atomic', 1, ['invalid'], 2);
    }

    public function testPaymentIntentRejectsNonPositiveUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentIntent(0, 1000);
    }

    public function testPaymentIntentRejectsNonPositiveAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentIntent(1, 0);
    }

    public function testPaymentIntentRejectsUnsupportedPaymentMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentIntent(1, 1000, 'crypto');
    }

    public function testPaymentIntentConstructsValidDefaultsAndOptions(): void
    {
        $cardIntent = new PaymentIntent(3, 2500);
        self::assertSame(3, $cardIntent->userId);
        self::assertSame(2500, $cardIntent->amountCents);
        self::assertSame('card', $cardIntent->paymentMethod);
        self::assertNull($cardIntent->idempotencyKey);
        self::assertNull($cardIntent->participantLabel);

        $cashIntent = new PaymentIntent(4, 1500, 'cash', 'key-123', 'Олена');
        self::assertSame(4, $cashIntent->userId);
        self::assertSame(1500, $cashIntent->amountCents);
        self::assertSame('cash', $cashIntent->paymentMethod);
        self::assertSame('key-123', $cashIntent->idempotencyKey);
        self::assertSame('Олена', $cashIntent->participantLabel);
    }
}
