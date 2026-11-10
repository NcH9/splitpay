<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly UserRepository $userRepository,
    ) {}

    #[Route('/', name: 'web_dashboard', methods: ['GET'])]
    #[Route('/dashboard', name: 'web_dashboard_alias', methods: ['GET'])]
    public function index(): Response
    {
        $orders = $this->orderRepository->findBy([], ['id' => 'DESC']);
        $users = $this->userRepository->findBy([], ['id' => 'ASC']);

        $totalOrders = count($orders);
        $totalTurnoverCents = 0;
        $totalPaidCents = 0;
        $openOrdersCount = 0;
        $paidOrdersCount = 0;

        foreach ($orders as $order) {
            $totalTurnoverCents += $order->getFullPriceCents();
            $totalPaidCents += $order->getPaidPriceCents();
            if ($order->getStatus() === OrderStatus::Open) {
                ++$openOrdersCount;
            } else {
                ++$paidOrdersCount;
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'orders' => $orders,
            'users' => $users,
            'stats' => [
                'totalOrders' => $totalOrders,
                'totalTurnoverCents' => $totalTurnoverCents,
                'totalPaidCents' => $totalPaidCents,
                'openOrdersCount' => $openOrdersCount,
                'paidOrdersCount' => $paidOrdersCount,
                'overallProgress' => $totalTurnoverCents > 0 ? (int) round(($totalPaidCents / $totalTurnoverCents) * 100) : 0,
            ],
        ]);
    }
}
