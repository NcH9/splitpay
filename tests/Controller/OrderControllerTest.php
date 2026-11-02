<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderControllerTest extends WebTestCase
{
    public function testShowReturns404ForNonExistentOrder(): void
    {
        $client = static::createClient();
        $client->request('GET', '/orders/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateRequiresPayload(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/orders',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        // Missing required fields → 422 Unprocessable Entity
        self::assertResponseStatusCodeSame(422);
    }
}
