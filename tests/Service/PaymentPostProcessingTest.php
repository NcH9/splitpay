<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\Payment\ProcessPaymentRequest;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Message\OrderFullyPaidEvent;
use App\Message\PaymentReceivedEvent;
use App\Payment\Idempotency\IdempotencyFilterInterface;
use App\Payment\PaymentResult;
use App\Payment\StrategyRegistry;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Service\PaymentService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PaymentPostProcessingTest extends TestCase
{
    public function testPayDispatchesPaymentReceivedAndOrderFullyPaidEvents(): void
    {
        $user = new User('Test Payer');
        $order = new Order('Laptop', 5000, $user);

        $orderRepository = $this->createMock(OrderRepository::class);
        $orderRepository->method('find')->with(1)->willReturn($order);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->with(1)->willReturn($user);

        $strategy = $this->createMock(\App\Payment\PaymentStrategyInterface::class);
        $strategy->method('getName')->willReturn('pessimistic');
        $strategyRegistry = new StrategyRegistry([$strategy]);

        $idempotencyFilter = $this->createMock(IdempotencyFilterInterface::class);
        $idempotencyFilter->expects(self::once())->method('acquire')->with('idem-post-1');
        $idempotencyFilter->expects(self::once())->method('commit')->with('idem-post-1');

        $payment = new \App\Entity\Payment(
            amountCents:    5000,
            paymentMethod:  \App\Enum\PaymentMethod::Card,
            idempotencyKey: 'idem-post-1',
            user:           $user,
            order:          $order,
        );

        $strategy->method('processPayment')->willReturnCallback(function () use ($order, $payment) {
            $order->setPaidPriceCents(5000);
            $order->setStatus(OrderStatus::Paid);

            return new PaymentResult($payment, 'pessimistic');
        });

        /** @var array<object> $dispatchedMessages */
        $dispatchedMessages = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedMessages) {
                $dispatchedMessages[] = $message;

                return new Envelope($message);
            });

        $service = new PaymentService(
            $orderRepository,
            $userRepository,
            $strategyRegistry,
            $idempotencyFilter,
            $messageBus,
        );

        $request = new ProcessPaymentRequest(
            amountCents:    5000,
            paymentMethod:  'card',
            idempotencyKey: 'idem-post-1',
            userId:         1,
            strategy:       'pessimistic',
        );

        $result = $service->pay(1, $request);

        self::assertSame('pessimistic', $result->strategyName);
        self::assertCount(2, $dispatchedMessages);

        self::assertInstanceOf(PaymentReceivedEvent::class, $dispatchedMessages[0]);
        self::assertSame(5000, $dispatchedMessages[0]->amountCents);
        self::assertSame('card', $dispatchedMessages[0]->paymentMethod);

        self::assertInstanceOf(OrderFullyPaidEvent::class, $dispatchedMessages[1]);
        self::assertSame(5000, $dispatchedMessages[1]->totalPaidCents);
    }

    public function testPayDoesNotDispatchOrderFullyPaidIfOrderRemainsOpen(): void
    {
        $user = new User('Test Partial Payer');
        $order = new Order('Headphones', 10000, $user);

        $orderRepository = $this->createMock(OrderRepository::class);
        $orderRepository->method('find')->with(2)->willReturn($order);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->with(1)->willReturn($user);

        $strategy = $this->createMock(\App\Payment\PaymentStrategyInterface::class);
        $strategy->method('getName')->willReturn('pessimistic');
        $strategyRegistry = new StrategyRegistry([$strategy]);

        $idempotencyFilter = $this->createMock(IdempotencyFilterInterface::class);

        $payment = new \App\Entity\Payment(
            amountCents:    4000,
            paymentMethod:  \App\Enum\PaymentMethod::Cash,
            idempotencyKey: 'idem-partial-1',
            user:           $user,
            order:          $order,
        );

        $strategy->method('processPayment')->willReturnCallback(function () use ($order, $payment) {
            $order->setPaidPriceCents(4000); // 4000 of 10000 -> remains Open

            return new PaymentResult($payment, 'pessimistic');
        });

        /** @var array<object> $dispatchedMessages */
        $dispatchedMessages = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedMessages) {
                $dispatchedMessages[] = $message;

                return new Envelope($message);
            });

        $service = new PaymentService(
            $orderRepository,
            $userRepository,
            $strategyRegistry,
            $idempotencyFilter,
            $messageBus,
        );

        $request = new ProcessPaymentRequest(
            amountCents:    4000,
            paymentMethod:  'cash',
            idempotencyKey: 'idem-partial-1',
            userId:         1,
            strategy:       'pessimistic',
        );

        $service->pay(2, $request);

        self::assertCount(1, $dispatchedMessages);
        self::assertInstanceOf(PaymentReceivedEvent::class, $dispatchedMessages[0]);
    }
}
