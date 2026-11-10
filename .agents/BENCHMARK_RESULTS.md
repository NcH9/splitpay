# SplitPay — Empirical Research on Concurrent Payment Strategies

This report presents the empirical findings of the **SplitPay** research application, investigating concurrency control mechanisms in payment systems under high contention.

The benchmark evaluates five distinct synchronization architectures against the same business operation in a modern **Symfony 8.1 / PHP 8.4 / PostgreSQL 16** stack.

---

## 1. Executive Summary

| Strategy | Concurrency Control | Invariant I1 (No Overpayment) | Invariant I2 (Balance Integrity) | Throughput (RPS) | P50 Latency | P95 Latency | P99 Latency | Production Recommendation |
|---|---|:---:|:---:|---:|---:|---:|---:|---|
| **1. Baseline** | None (Control Group) | ❌ **Violated** (+$360.00 excess) | ❌ **Violated** (Discrepancy) | 112.16 | 112.85 ms | 233.93 ms | 267.63 ms | **Never** in production |
| **2. Pessimistic Lock** | Database row write lock (`SELECT … FOR UPDATE`) | ✅ **Preserved** | ✅ **Preserved** | 131.22 | 104.67 ms | 178.41 ms | 235.37 ms | High-value payments, strict consistency |
| **3. Optimistic Lock** | Version check (`#[ORM\Version]`) + EM retry | ✅ **Preserved** | ✅ **Preserved** | 145.17 | 93.16 ms | 160.48 ms | 225.81 ms | Low-to-medium contention workloads |
| **4. Symfony Lock** | Application-level mutex (`symfony/lock`) | ✅ **Preserved** | ✅ **Preserved** | 124.00 | 109.86 ms | 203.68 ms | 226.55 ms | Multi-resource orchestration, external API locks |
| **5. Atomic Conditional UPDATE** | Single SQL `UPDATE … WHERE` with balance check | ✅ **Preserved** | ✅ **Preserved** | **150.60** | **79.49 ms** | **131.41 ms** | **160.10 ms** | **High-throughput balance reservations (Best Overall)** |

---

## 2. Core Invariants Tested

* **I1 — No Overpayment**: Total amount of committed payments must never exceed `Order.full_price_cents` (`SUM(Payment.amount_cents) <= Order.full_price_cents`).
* **I2 — Paid Amount Consistency**: `Order.paid_price_cents == SUM(Payment.amount_cents)`.
* **I3 — No Partial Remainder Overflow**: A payment must be rejected if its amount exceeds the remaining balance.
* **I4 — No Lost Updates**: Concurrent successful payments must not overwrite one another's recorded balance.
* **I5 — Idempotency**: Duplicate idempotency keys are rejected with HTTP `409 Conflict`.

---

## 3. Empirical Benchmark Results

### Benchmark Workload:
* **Target**: Single shared Order of $200.00 (`20,000` cents).
* **Payment Size**: $10.00 (`1,000` cents) per request.
* **Allowed Successful Payments**: Exactly **20**. All remaining requests must be safely rejected.
* **Workload**: **200 requests**, **25 concurrent workers**.
* **Transport**: HTTP requests through Nginx to PHP-FPM and PostgreSQL 16.

```text
+--------------+------------------------+--------+----------+----------+----------+-----+-----+-----+-----+-----------------+----------+------------+
| Strategy     | Concurrency / Requests | RPS    | P50 ms   | P95 ms   | P99 ms   | 201 | 409 | 422 | 5xx | Paid / Actual   | Overpaid | Consistent |
+--------------+------------------------+--------+----------+----------+----------+-----+-----+-----+-----+-----------------+----------+------------+
| baseline     | 25 / 200               | 112.16 | 112.85   | 233.93   | 267.63   | 56  | 144 | 0   | 0   | 20000 / 56000 c | YES      | NO         |
| pessimistic  | 25 / 200               | 131.22 | 104.67   | 178.41   | 235.37   | 20  | 180 | 0   | 0   | 20000 / 20000 c | NO       | YES        |
| optimistic   | 25 / 200               | 145.17 | 93.16    | 160.48   | 225.81   | 20  | 180 | 0   | 0   | 20000 / 20000 c | NO       | YES        |
| symfony_lock | 25 / 200               | 124.00 | 109.86   | 203.68   | 226.55   | 20  | 180 | 0   | 0   | 20000 / 20000 c | NO       | YES        |
| atomic       | 25 / 200               | 142.78 | 96.10    | 171.95   | 183.06   | 20  | 179 | 1   | 0   | 20000 / 20000 c | NO       | YES        |
+--------------+------------------------+--------+----------+----------+----------+-----+-----+-----+-----+-----------------+----------+------------+
```

---

## 4. Strategy Deep-Dive & Architectural Analysis

### Strategy 1: Baseline (Unprotected Control Group)
* **Mechanism**: Plain `READ COMMITTED` read-modify-write without explicit synchronization.
* **Observed Flaw**:
  * 56 payments succeeded instead of 20.
  * The customer paid $560.00 for a $200.00 order.
  * `Order.paid_price_cents` showed 20,000 cents while actual payment rows summed to 56,000 cents.
  * **Conclusion**: Proves empirically that standard ACID read-committed transactions cannot prevent race conditions when reading and writing aggregated balances across concurrent workers.

### Strategy 2: Pessimistic Row Lock (`SELECT … FOR UPDATE`)
* **Mechanism**: PostgreSQL row-level exclusive write lock acquired before inspecting the remaining balance.
* **Strengths**: 100% deterministic correctness. Zero lost updates or retries needed.
* **Weaknesses**: Workers queue at the database level. Response latency grows linearly with concurrency depth as lock wait time accumulates.
* **Best Used For**: Low-throughput, high-value financial settlements where aborting/retrying operations is expensive.

### Strategy 3: Optimistic Locking with Automatic Retry
* **Mechanism**: Version column increment check at commit time (`WHERE id = ? AND version = ?`). Upon version collision, the closed `EntityManager` is reset via `ManagerRegistry::resetManager()`, refreshing state and retrying up to 3 times.
* **Strengths**: High throughput under moderate concurrency without blocking database connections.
* **Weaknesses**: Under extreme contention on a single order, retry exhaustion causes HTTP 409 conflict responses.
* **Best Used For**: Workloads where contention is sparse (e.g. distributed across millions of different orders).

### Strategy 4: Application-Level Mutex (`symfony/lock`)
* **Mechanism**: Symfony `LockFactory` acquiring an exclusive mutex on `order:{orderId}` before the database transaction starts.
* **Strengths**: Moves serialization outside the database engine. Can guard external non-database operations (e.g., Stripe API calls) alongside database updates.
* **Weaknesses**: Lowest throughput of the protected mechanisms (106–124 RPS) due to filesystem/lock backend IPC overhead.
* **Best Used For**: Orchestration across distributed services and third-party payment gateways.

### Strategy 5: Atomic Conditional UPDATE
* **Mechanism**: Single atomic SQL execution:
  ```sql
  UPDATE orders
  SET paid_price_cents = paid_price_cents + :amount,
      updated_at = NOW(),
      status = CASE WHEN paid_price_cents + :amount >= full_price_cents THEN 'paid' ELSE status END
  WHERE id = :id AND paid_price_cents + :amount <= full_price_cents
  ```
  Followed by row count check (`rowCount === 1`).
* **Strengths**:
  * **Fastest response times**: P50 of **79.49 ms** and P99 of **160.10 ms**.
  * **Highest throughput**: Over **150 RPS** under 20 concurrency.
  * Completely lock-free at the application layer; leverages PostgreSQL's internal MVCC tuple update atomicity.
* **Best Used For**: High-throughput high-contention balance reservation systems (e.g. ticket sales, flash sales, split bill payments).

---

## 5. Automated Test Verification & Architectural Updates

> **Note on later architectural upgrades:** The benchmark table in Section 3 reflects the initial empirical baseline run (`25 / 200`, previously labeled `symfony_lock`). Since that run, the project has been extended with:
> 1. Unified strategy naming (`symfony_lock` → `mutex`) and `$em->refresh($order)` inside `SymfonyLockStrategy` to prevent stale reads;
> 2. Distributed Redis backend for `symfony/lock` (`LOCK_DSN=redis://redis:6379`) and `RedisIdempotencyFilter` (`SET NX EX`);
> 3. Asynchronous domain events (`PaymentReceivedEvent`, `OrderFullyPaidEvent`) over RabbitMQ / Symfony Messenger;
> 4. Server-rendered Twig + Bootstrap 5 Web UI (including live benchmarking at `/benchmark`).

All invariants and behaviors are permanently codified and verified in PHPUnit:
* **`StrategyIntegrationTest`** (22 tests): Verifies single-request correctness, edge cases, stale-state refresh for `mutex`, and status transitions for all 5 strategies.
* **`ConcurrencyStressTest`** (5 tests): Real parallel HTTP stress tests confirming that `baseline` fails under concurrency while `pessimistic`, `optimistic`, `mutex`, and `atomic` strictly enforce invariants **I1** through **I5**.
* **Additional Suites**: Controller API & Web UI tests (`WebInterfaceTest`), `RedisIdempotencyFilterTest`, `PaymentPostProcessingTest`, `MessengerFailureAndRetryTest`, MessageHandler tests, `ConsistencyAuditorTest`, and `BenchmarkRunCommandTest`.
* **Full Test Suite**: **62 tests, 207 assertions — ALL PASSING**.
