<?php

namespace App\Service;

use App\Entity\Order;
use App\Repository\OrderRepository;

class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository,
    ) {}

    public function create($request): Order
    {
        $order = new Order();

        //
        $this->orderRepository->save($order);

        return $order;
    }

    public function getOrderById(int $id): ?Order
    {
        return $this->orderRepository->find($id);
    }
}