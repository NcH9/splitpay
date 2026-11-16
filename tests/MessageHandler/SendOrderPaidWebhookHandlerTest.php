<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Message\OrderFullyPaidEvent;
use App\MessageHandler\SendOrderPaidWebhookHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SendOrderPaidWebhookHandlerTest extends TestCase
{
    public function testInvokeLogsOutgoingWebhook(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                self::stringContains('[Webhook] Order #7 fully paid ($100.00). Dispatched order.paid webhook to merchant endpoint.'),
                self::callback(static function (array $context): bool {
                    return $context['orderId'] === 7
                        && $context['userId'] === 2
                        && $context['totalPaidCents'] === 10000;
                }),
            );

        $handler = new SendOrderPaidWebhookHandler($logger);
        $event = new OrderFullyPaidEvent(
            orderId:        7,
            userId:         2,
            totalPaidCents: 10000,
            occurredAt:     (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        );

        $handler($event);
    }
}
