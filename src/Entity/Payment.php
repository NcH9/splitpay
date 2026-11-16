<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentMethod;
use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Payment amount in integer minor units (e.g. $10.50 → 1050).
     */
    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod;

    /**
     * Client-supplied idempotency key — prevents duplicate payment submissions.
     * Invariant I5: unique per request.
     */
    #[ORM\Column(length: 255, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Order $order = null;

    public function __construct(
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
        User $user,
        Order $order,
    ) {
        $this->amountCents    = $amountCents;
        $this->paymentMethod  = $paymentMethod;
        $this->idempotencyKey = $idempotencyKey;
        $this->user           = $user;
        $this->order          = $order;
        $this->createdAt      = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): static
    {
        $this->order = $order;

        return $this;
    }
}
