<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\OrderFullyPaidEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asynchronous handler simulating an outgoing webhook dispatched to external merchant services
 * when an order reaches Paid status.
 */
#[AsMessageHandler]
final class SendOrderPaidWebhookHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(OrderFullyPaidEvent $event): void
    {
        // Simulate outgoing webhook payload delivery to merchant/ERP
        $this->logger->info(
            sprintf(
                '[Webhook] Order #%d fully paid ($%.2f). Dispatched order.paid webhook to merchant endpoint.',
                $event->orderId,
                $event->totalPaidCents / 100,
            ),
            [
                'orderId'        => $event->orderId,
                'userId'         => $event->userId,
                'totalPaidCents' => $event->totalPaidCents,
                'occurredAt'     => $event->occurredAt,
            ],
        );
    }
}
