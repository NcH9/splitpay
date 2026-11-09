<?php

declare(strict_types=1);

namespace App\Tests\Controller\Web;

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
}
