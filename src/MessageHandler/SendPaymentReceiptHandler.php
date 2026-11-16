<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PaymentReceivedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asynchronous handler simulating receipt/invoice delivery to the paying user.
 */
#[AsMessageHandler]
final class SendPaymentReceiptHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PaymentReceivedEvent $event): void
    {
        // Simulate sending receipt / invoice to customer
        $this->logger->info(
            sprintf(
                '[Receipt] Successfully dispatched receipt for payment #%d ($%.2f via %s) to user #%d for order #%d.',
                $event->paymentId,
                $event->amountCents / 100,
                $event->paymentMethod,
                $event->userId,
                $event->orderId,
            ),
            [
                'paymentId' => $event->paymentId,
                'orderId'   => $event->orderId,
                'userId'    => $event->userId,
                'amountCents' => $event->amountCents,
                'idempotencyKey' => $event->idempotencyKey,
            ],
        );
    }
}
