<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PaymentController — stub for Section 2.
 * Full implementation (ProcessPaymentRequest + PaymentStrategyResolver) is added in Section 2.
 */
#[Route('/payments', name: 'payment_')]
final class PaymentController extends AbstractController
{
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        throw $this->createNotFoundException('Not implemented yet.');
    }
}
