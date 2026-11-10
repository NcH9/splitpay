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

    public function testCreateAndShowPaymentSuccess(): void
    {
        $client = static::createClient();

        // 1. Create a user
        $client->request(
            'POST',
            '/users',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Payment Test User'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $userData = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $userId = $userData['id'];

        // 2. Create an order
        $client->request(
            'POST',
            '/orders',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Payment Test Order',
                'fullPriceCents' => 5000,
                'userId' => $userId,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $orderData = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $orderId = $orderData['id'];

        // 3. Process payment with 'pessimistic' strategy
        $client->request(
            'POST',
            '/payments/' . $orderId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'amountCents' => 2000,
                'paymentMethod' => 'card',
                'idempotencyKey' => 'http-pay-' . bin2hex(random_bytes(6)),
                'userId' => $userId,
                'strategy' => 'pessimistic',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $paymentData = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2000, $paymentData['amountCents']);
        self::assertSame('pessimistic', $paymentData['strategyUsed']);
        self::assertSame($orderId, $paymentData['orderId']);
        $paymentId = $paymentData['id'];

        // 4. Show payment
        $client->request('GET', '/payments/' . $paymentId);
        self::assertResponseStatusCodeSame(200);
        $showData = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($paymentId, $showData['id']);
        self::assertSame(2000, $showData['amountCents']);
    }

    public function testCreateRejectsOverpaymentAndDuplicateIdempotency(): void
    {
        $client = static::createClient();

        // Create user & order
        $client->request('POST', '/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Conflict User'], JSON_THROW_ON_ERROR));
        $userId = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        $client->request('POST', '/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Conflict Order',
            'fullPriceCents' => 1000,
            'userId' => $userId,
        ], JSON_THROW_ON_ERROR));
        $orderId = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        $idempotencyKey = 'idem-' . bin2hex(random_bytes(6));

        // 1. Valid payment
        $client->request('POST', '/payments/' . $orderId, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'amountCents' => 800,
            'paymentMethod' => 'cash',
            'idempotencyKey' => $idempotencyKey,
            'userId' => $userId,
            'strategy' => 'atomic',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // 2. Duplicate idempotency key -> 409 Conflict
        $client->request('POST', '/payments/' . $orderId, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'amountCents' => 100,
            'paymentMethod' => 'cash',
            'idempotencyKey' => $idempotencyKey,
            'userId' => $userId,
            'strategy' => 'atomic',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);

        // 3. Overpayment (balance remaining is 200 cents, paying 500) -> 422 Unprocessable Entity
        $client->request('POST', '/payments/' . $orderId, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'amountCents' => 500,
            'paymentMethod' => 'card',
            'idempotencyKey' => 'idem-overpay-' . bin2hex(random_bytes(6)),
            'userId' => $userId,
            'strategy' => 'atomic',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }
}
