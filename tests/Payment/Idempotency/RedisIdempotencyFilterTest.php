<?php

declare(strict_types=1);

namespace App\Tests\Payment\Idempotency;

use App\Exception\IdempotencyConflictException;
use App\Payment\Idempotency\IdempotencyFilterInterface;
use App\Payment\Idempotency\RedisIdempotencyFilter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RedisIdempotencyFilterTest extends KernelTestCase
{
    private IdempotencyFilterInterface $filter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->filter = static::getContainer()->get(IdempotencyFilterInterface::class);
    }

    public function testAcquireSuccessfullyReservesNewKey(): void
    {
        $key = 'test-reserve-' . bin2hex(random_bytes(8));

        // First attempt must succeed without throwing
        $this->filter->acquire($key);

        // Cleanup
        $this->filter->release($key);
        self::assertTrue(true);
    }

    public function testAcquireThrowsConflictOnDuplicateKey(): void
    {
        $key = 'test-dup-' . bin2hex(random_bytes(8));

        $this->filter->acquire($key);

        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessage(sprintf('A payment with idempotency key "%s" already exists.', $key));

        try {
            $this->filter->acquire($key);
        } finally {
            $this->filter->release($key);
        }
    }

    public function testReleaseAllowsReacquiringKey(): void
    {
        $key = 'test-release-' . bin2hex(random_bytes(8));

        $this->filter->acquire($key);
        $this->filter->release($key);

        // After release, the key must be immediately available again
        $this->filter->acquire($key);
        $this->filter->release($key);

        self::assertTrue(true);
    }

    public function testCommitFinalizesKeyAndBlocksFutureRequests(): void
    {
        $key = 'test-commit-' . bin2hex(random_bytes(8));

        $this->filter->acquire($key);
        $this->filter->commit($key, 42);

        $this->expectException(IdempotencyConflictException::class);

        try {
            $this->filter->acquire($key);
        } finally {
            $this->filter->release($key);
        }
    }
}
