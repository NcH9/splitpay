<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\OrderFullyPaidEvent;
use App\Message\PaymentReceivedEvent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class MessengerFailureAndRetryTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testRetryStrategyParameters(): void
    {
        // Verify exponential backoff: 3 retries, initial delay 1000ms, multiplier 2
        $strategy = new MultiplierRetryStrategy(
            maxRetries: 3,
            delayMilliseconds: 1000,
            multiplier: 2.0,
            maxDelayMilliseconds: 10000,
            jitter: 0.0,
        );

        $envelope = new Envelope(new PaymentReceivedEvent(
            paymentId: 1,
            orderId: 1,
            userId: 1,
            amountCents: 1000,
            paymentMethod: 'card',
            idempotencyKey: 'retry-test-1',
            occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ));

        // Retry 1: 1000ms
        self::assertTrue($strategy->isRetryable($envelope));
        self::assertSame(1000, $strategy->getWaitingTime($envelope));

        // Retry 2: 2000ms
        $retryEnvelope1 = $envelope->with(new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(1));
        self::assertTrue($strategy->isRetryable($retryEnvelope1));
        self::assertSame(2000, $strategy->getWaitingTime($retryEnvelope1));

        // Retry 3: 4000ms
        $retryEnvelope2 = $envelope->with(new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(2));
        self::assertTrue($strategy->isRetryable($retryEnvelope2));
        self::assertSame(4000, $strategy->getWaitingTime($retryEnvelope2));

        // Retry 4: Exceeded max_retries (3) -> Not retryable -> DLQ
        $retryEnvelope3 = $envelope->with(new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(3));
        self::assertFalse($strategy->isRetryable($retryEnvelope3));
    }
}
