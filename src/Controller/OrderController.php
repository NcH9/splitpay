<?php

namespace App\Controller;

use App\DTO\Order\CreateOrderRequest;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    public function __construct(
        private OrderService $orderService
    ) {}

    #[Route('/order', name: 'app_order')]
    public function store(#[MapRequestPayload] CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create($request);

        return $this->json([
            'message' => 'Order created successfully!',
        ], 201);
    }
}
