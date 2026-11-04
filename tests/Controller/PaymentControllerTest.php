<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PaymentControllerTest extends WebTestCase
{
    public function testCreateReturns404ForNonExistentOrder(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/payments/999999',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'amountCents'    => 1000,
                'paymentMethod'  => 'card',
                'idempotencyKey' => 'test-key-404',
                'userId'         => 1,
                'strategy'       => 'baseline',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateReturns422WhenPayloadMissingRequiredFields(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/payments/1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        // MapRequestPayload validates before the controller runs
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateReturns422WhenAmountIsNegative(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/payments/1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'amountCents'    => -500,
                'paymentMethod'  => 'card',
                'idempotencyKey' => 'test-key-neg',
                'userId'         => 1,
                'strategy'       => 'baseline',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateReturns422WhenStrategyIsInvalid(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/payments/1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'amountCents'    => 1000,
                'paymentMethod'  => 'card',
                'idempotencyKey' => 'test-key-invalid-strategy',
                'userId'         => 1,
                'strategy'       => 'nonexistent',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateReturns422WhenPaymentMethodIsInvalid(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/payments/1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'amountCents'    => 1000,
                'paymentMethod'  => 'bitcoin',
                'idempotencyKey' => 'test-key-bad-method',
                'userId'         => 1,
                'strategy'       => 'baseline',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testShowReturns404ForNonExistentPayment(): void
    {
        $client = static::createClient();
        $client->request('GET', '/payments/999999');

        self::assertResponseStatusCodeSame(404);
    }
}
