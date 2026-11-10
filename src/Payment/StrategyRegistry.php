<?php

declare(strict_types=1);

namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects all PaymentStrategyInterface implementations (auto-tagged via the interface)
 * and resolves them by their machine-readable name at runtime.
 *
 * Usage:
 *   $strategy = $registry->get('pessimistic');
 *   $result   = $strategy->processPayment(...);
 */
final class StrategyRegistry
{
    /** @var array<string, PaymentStrategyInterface> */
    private array $map = [];

    public function __construct(
        #[AutowireIterator('app.payment_strategy')]
        iterable $strategies,
    ) {
        foreach ($strategies as $strategy) {
            $this->map[$strategy->getName()] = $strategy;
        }
    }

    /**
     * @throws \InvalidArgumentException for unknown strategy names
     */
    public function get(string $name): PaymentStrategyInterface
    {
        if (!isset($this->map[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown payment strategy "%s". Available: %s.',
                $name,
                implode(', ', $this->names()),
            ));
        }

        return $this->map[$name];
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->map);
    }
}
