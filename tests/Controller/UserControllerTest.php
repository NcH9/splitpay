<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserControllerTest extends WebTestCase
{
    public function testShowReturns404ForNonExistentUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/users/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateRequiresName(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/users',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        // Missing required `name` → 422 Unprocessable Entity
        self::assertResponseStatusCodeSame(422);
    }
}
