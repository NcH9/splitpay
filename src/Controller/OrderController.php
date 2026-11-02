<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Order\CreateOrderRequest;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/orders', name: 'order_')]
final class OrderController extends AbstractController
{
    public function __construct(
        private OrderService $orderService,
    ) {}

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $order = $this->orderService->getOrderById($id);

        if ($order === null) {
            throw $this->createNotFoundException(sprintf('Order #%d not found.', $id));
        }

        return $this->json([
            'id'              => $order->getId(),
            'name'            => $order->getName(),
            'fullPriceCents'  => $order->getFullPriceCents(),
            'paidPriceCents'  => $order->getPaidPriceCents(),
            'remainingCents'  => $order->getRemainingCents(),
            'status'          => $order->getStatus()->value,
            'version'         => $order->getVersion(),
            'createdAt'       => $order->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'       => $order->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function store(#[MapRequestPayload] CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create($request);

        return $this->json([
            'id'             => $order->getId(),
            'name'           => $order->getName(),
            'fullPriceCents' => $order->getFullPriceCents(),
            'paidPriceCents' => $order->getPaidPriceCents(),
            'status'         => $order->getStatus()->value,
            'version'        => $order->getVersion(),
            'createdAt'      => $order->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], 201);
    }
}
