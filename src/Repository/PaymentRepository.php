<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function save(Payment $payment, bool $flush = true): void
    {
        $this->getEntityManager()->persist($payment);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Returns the actual sum of all payment amounts for the given order.
     * Used by the ConsistencyAuditor to verify invariant I2.
     */
    public function sumAmountCentsByOrderId(int $orderId): int
    {
        $result = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amountCents), 0)')
            ->where('p.order = :orderId')
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }
}
