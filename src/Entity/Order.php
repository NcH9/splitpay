<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    /**
     * Total price of the order in integer minor units (e.g. $10.50 → 1050).
     */
    #[ORM\Column]
    private int $fullPriceCents;

    /**
     * Denormalized sum of all successful Payment.amountCents for this order.
     * Source of truth is the SUM of Payment records; this field is a fast-read cache.
     */
    #[ORM\Column]
    private int $paidPriceCents = 0;

    #[ORM\Column(enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::Open;

    /**
     * Optimistic lock version — required for Strategy 3 (OptimisticLockStrategy).
     * Must be typed as 'integer' explicitly; Doctrine ORM 3 cannot infer the type for version fields.
     */
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: Payment::class)]
    private Collection $payments;

    public function __construct(string $name, int $fullPriceCents, User $user)
    {
        $this->name          = $name;
        $this->fullPriceCents = $fullPriceCents;
        $this->user          = $user;
        $this->createdAt     = new \DateTimeImmutable();
        $this->updatedAt     = new \DateTimeImmutable();
        $this->payments      = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getFullPriceCents(): int
    {
        return $this->fullPriceCents;
    }

    public function setFullPriceCents(int $fullPriceCents): static
    {
        $this->fullPriceCents = $fullPriceCents;

        return $this;
    }

    public function getPaidPriceCents(): int
    {
        return $this->paidPriceCents;
    }

    public function setPaidPriceCents(int $paidPriceCents): static
    {
        $this->paidPriceCents = $paidPriceCents;

        return $this;
    }

    /**
     * Returns how many cents are still available on this order.
     */
    public function getRemainingCents(): int
    {
        return $this->fullPriceCents - $this->paidPriceCents;
    }

    /**
     * Returns true when there is no remaining balance.
     */
    public function isFullyPaid(): bool
    {
        return $this->paidPriceCents >= $this->fullPriceCents;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function setStatus(OrderStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
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

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payment $payment): static
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setOrder($this);
        }

        return $this;
    }

    public function removePayment(Payment $payment): static
    {
        if ($this->payments->removeElement($payment)) {
            if ($payment->getOrder() === $this) {
                $payment->setOrder(null);
            }
        }

        return $this;
    }
}
