<?php

declare(strict_types=1);

namespace App\Command;

use App\Benchmark\BenchmarkResult;
use App\Benchmark\BenchmarkRunner;
use App\Benchmark\PaymentIntent;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:benchmark:run', description: 'Stress-test payment strategies and audit database consistency.')]
final class BenchmarkRunCommand extends Command
{
    private const STRATEGIES = ['baseline', 'pessimistic', 'optimistic', 'mutex', 'atomic'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BenchmarkRunner $benchmarkRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'Strategy to benchmark: baseline, pessimistic, optimistic, mutex, atomic, or all.', 'baseline')
            ->addOption('users', null, InputOption::VALUE_REQUIRED, 'Number of distinct concurrent payer users.', '1')
            ->addOption('requests', null, InputOption::VALUE_REQUIRED, 'Number of HTTP requests.', '50')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Maximum concurrent HTTP requests.', '10')
            ->addOption('order-full-price', null, InputOption::VALUE_REQUIRED, 'Order total in cents.', '10000')
            ->addOption('payment-amount', null, InputOption::VALUE_REQUIRED, 'Payment amount in cents.', '1000')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, markdown, or json.', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $strategy = (string) $input->getOption('strategy');
        $usersCount = filter_var($input->getOption('users'), FILTER_VALIDATE_INT);
        $requests = filter_var($input->getOption('requests'), FILTER_VALIDATE_INT);
        $concurrency = filter_var($input->getOption('concurrency'), FILTER_VALIDATE_INT);
        $orderFullPriceCents = filter_var($input->getOption('order-full-price'), FILTER_VALIDATE_INT);
        $paymentAmountCents = filter_var($input->getOption('payment-amount'), FILTER_VALIDATE_INT);
        $format = (string) $input->getOption('format');

        if (!in_array($strategy, [...self::STRATEGIES, 'all'], true)) {
            $output->writeln('<error>Invalid strategy. Choose baseline, pessimistic, optimistic, mutex, atomic, or all.</error>');

            return Command::INVALID;
        }
        if (!in_array($format, ['table', 'markdown', 'json'], true)) {
            $output->writeln('<error>Invalid format. Choose table, markdown, or json.</error>');

            return Command::INVALID;
        }
        if (!is_int($usersCount) || $usersCount < 1) {
            $output->writeln('<error>Users must be a positive integer.</error>');

            return Command::INVALID;
        }
        if (!is_int($requests) || !is_int($concurrency) || !is_int($orderFullPriceCents) || !is_int($paymentAmountCents)
            || $requests < 1 || $concurrency < 1 || $orderFullPriceCents < 1 || $paymentAmountCents < 1) {
            $output->writeln('<error>Requests, concurrency, order price, and payment amount must be positive integers.</error>');

            return Command::INVALID;
        }

        $strategies = $strategy === 'all' ? self::STRATEGIES : [$strategy];
        $results = [];

        try {
            foreach ($strategies as $currentStrategy) {
                if ($usersCount === 1) {
                    [$orderId, $userId] = $this->createScenario($orderFullPriceCents);
                    $results[] = $this->benchmarkRunner->run(
                        $currentStrategy,
                        $orderId,
                        $userId,
                        $requests,
                        $concurrency,
                        $paymentAmountCents,
                    );
                } else {
                    [$orderId, $userIds] = $this->createMultiUserScenario($orderFullPriceCents, $usersCount);
                    $intents = [];
                    for ($i = 0; $i < $requests; ++$i) {
                        $intents[] = new PaymentIntent(
                            userId: $userIds[$i % $usersCount],
                            amountCents: $paymentAmountCents,
                            paymentMethod: $i % 2 === 0 ? 'card' : 'cash',
                        );
                    }
                    $results[] = $this->benchmarkRunner->runConcurrentPayments(
                        $currentStrategy,
                        $orderId,
                        $intents,
                        $concurrency,
                    )->benchmark;
                }
            }
        } catch (\Throwable $exception) {
            $output->writeln(sprintf('<error>Benchmark failed: %s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $this->renderResults($results, $format, $output, $usersCount);

        return Command::SUCCESS;
    }

    /** @return array{int, int} order ID then user ID */
    private function createScenario(int $orderFullPriceCents): array
    {
        $user = new User('Benchmark User ' . bin2hex(random_bytes(5)));
        $order = new Order('Benchmark Order', $orderFullPriceCents, $user);
        $this->entityManager->persist($user);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $orderId = $order->getId();
        $userId = $user->getId();
        if ($orderId === null || $userId === null) {
            throw new \RuntimeException('Failed to persist the benchmark scenario.');
        }

        return [$orderId, $userId];
    }

    /** @return array{int, list<int>} order ID then list of user IDs */
    private function createMultiUserScenario(int $orderFullPriceCents, int $usersCount): array
    {
        $users = [];
        for ($u = 0; $u < $usersCount; ++$u) {
            $user = new User('Benchmark User #' . ($u + 1) . ' ' . bin2hex(random_bytes(4)));
            $this->entityManager->persist($user);
            $users[] = $user;
        }

        $order = new Order('Benchmark Order', $orderFullPriceCents, $users[0]);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $orderId = $order->getId();
        if ($orderId === null) {
            throw new \RuntimeException('Failed to persist the benchmark scenario.');
        }

        $userIds = [];
        foreach ($users as $user) {
            $userId = $user->getId();
            if ($userId === null) {
                throw new \RuntimeException('Failed to persist the benchmark scenario.');
            }
            $userIds[] = $userId;
        }

        return [$orderId, $userIds];
    }

    /** @param list<BenchmarkResult> $results */
    private function renderResults(array $results, string $format, OutputInterface $output, int $usersCount): void
    {
        if ($format === 'json') {
            $output->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return;
        }

        $headers = ['Strategy', 'Concurrency / Requests', 'Users', 'RPS', 'P50 ms', 'P95 ms', 'P99 ms', '201', '409', '422', '5xx', 'Paid / Actual', 'Overpaid', 'Consistent'];
        $rows = array_map(fn (BenchmarkResult $result): array => $this->row($result, $usersCount), $results);

        if ($format === 'table') {
            $table = new Table($output);
            $table->setHeaders($headers)->setRows($rows)->render();

            return;
        }

        $output->writeln('| ' . implode(' | ', $headers) . ' |');
        $output->writeln('|' . implode('|', array_fill(0, count($headers), '---')) . '|');
        foreach ($rows as $row) {
            $output->writeln('| ' . implode(' | ', array_map(static fn (string|int|float $value): string => str_replace('|', '\\|', (string) $value), $row)) . ' |');
        }
    }

    /** @return list<string|int|float> */
    private function row(BenchmarkResult $result, int $usersCount): array
    {
        $audit = $result->audit;

        return [
            $result->strategy,
            sprintf('%d / %d', $result->concurrency, $result->totalRequests),
            $usersCount,
            number_format($result->requestsPerSecond, 2),
            number_format($result->latencyP50Ms, 2),
            number_format($result->latencyP95Ms, 2),
            number_format($result->latencyP99Ms, 2),
            $result->status201Count,
            $result->status409Count,
            $result->status422Count,
            $result->status5xxCount,
            sprintf('%d / %d', $audit->orderPaidPriceCents, $audit->actualPaymentSumCents),
            $audit->isOverpaid ? 'YES' : 'NO',
            $audit->isConsistent ? 'YES' : 'NO',
        ];
    }
}
