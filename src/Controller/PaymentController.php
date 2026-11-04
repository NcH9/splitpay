<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Payment\ProcessPaymentRequest;
use App\Exception\IdempotencyConflictException;
use App\Exception\OrderNotOpenException;
use App\Exception\OverpaymentException;
use App\Repository\PaymentRepository;
use App\Service\PaymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/payments', name: 'payment_')]
final class PaymentController extends AbstractController
{
    public function __construct(
        private PaymentService $paymentService,
        private PaymentRepository $paymentRepository,
    ) {}

    /**
     * POST /payments/{orderId}
     *
     * Process a payment against the specified order using the requested
     * synchronization strategy. Strategy is selected per-request via the
     * `strategy` field in the JSON body, allowing the benchmark to exercise
     * all five implementations against the same endpoint.
     */
    #[Route('/{orderId}', name: 'create', methods: ['POST'], requirements: ['orderId' => '\d+'])]
    public function create(
        int $orderId,
        #[MapRequestPayload] ProcessPaymentRequest $request,
    ): JsonResponse {
        try {
            $result = $this->paymentService->pay($orderId, $request);
        } catch (OverpaymentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (OrderNotOpenException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        } catch (IdempotencyConflictException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        $payment = $result->payment;

        return $this->json([
            'id'              => $payment->getId(),
            'orderId'         => $payment->getOrder()?->getId(),
            'userId'          => $payment->getUser()?->getId(),
            'amountCents'     => $payment->getAmountCents(),
            'paymentMethod'   => $payment->getPaymentMethod()->value,
            'idempotencyKey'  => $payment->getIdempotencyKey(),
            'strategyUsed'    => $result->strategyName,
            'retriesAttempted' => $result->retriesAttempted,
            'createdAt'       => $payment->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    /**
     * GET /payments/{id}
     *
     * Retrieve a single payment record by its ID.
     * Used by the benchmark auditor to verify consistency after a run.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $payment = $this->paymentRepository->find($id);

        if ($payment === null) {
            throw $this->createNotFoundException(sprintf('Payment #%d not found.', $id));
        }

        return $this->json([
            'id'             => $payment->getId(),
            'orderId'        => $payment->getOrder()?->getId(),
            'userId'         => $payment->getUser()?->getId(),
            'amountCents'    => $payment->getAmountCents(),
            'paymentMethod'  => $payment->getPaymentMethod()->value,
            'idempotencyKey' => $payment->getIdempotencyKey(),
            'createdAt'      => $payment->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }
}
