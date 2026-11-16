<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Order\CreateOrderRequest;
use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private UserRepository $userRepository,
    ) {}

    public function create(CreateOrderRequest $request): Order
    {
        $user = $this->userRepository->find($request->userId);

        if ($user === null) {
            throw new NotFoundHttpException(sprintf('User #%d not found.', $request->userId));
        }

        $order = new Order($request->name, $request->fullPriceCents, $user);
        $this->orderRepository->save($order);

        return $order;
    }

    public function getOrderById(int $id): ?Order
    {
        return $this->orderRepository->find($id);
    }
}
