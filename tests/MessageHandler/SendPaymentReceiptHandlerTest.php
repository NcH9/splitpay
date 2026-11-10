<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Message\PaymentReceivedEvent;
use App\MessageHandler\SendPaymentReceiptHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SendPaymentReceiptHandlerTest extends TestCase
{
    public function testInvokeLogsReceiptDelivery(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                self::stringContains('[Receipt] Successfully dispatched receipt for payment #10 ($25.00 via card) to user #1 for order #5.'),
                self::callback(static function (array $context): bool {
                    return $context['paymentId'] === 10
                        && $context['orderId'] === 5
                        && $context['amountCents'] === 2500;
                }),
            );

        $handler = new SendPaymentReceiptHandler($logger);
        $event = new PaymentReceivedEvent(
            paymentId:      10,
            orderId:        5,
            userId:         1,
            amountCents:    2500,
            paymentMethod:  'card',
            idempotencyKey: 'test-receipt-idem',
            occurredAt:     (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        );

        $handler($event);
    }
}
