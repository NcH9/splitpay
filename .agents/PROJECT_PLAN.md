# SplitPay — Project Plan

## 1. Project Purpose

**SplitPay** is a compact Symfony application for studying concurrent payment operations for a shared order.

The application models an order that can be paid through multiple independent payments.

Examples:

* one user pays part by card and part by cash;
* several users share one order;
* different users pay different parts of the same order;
* users can use different payment methods.

Example:

```text
Order total: $100

User A → $40 → card
User B → $30 → cash
User C → $30 → card

Total paid: $100
```

The project is intentionally small.

Its primary purpose is **not** to implement a production payment platform. The main purpose is to create a realistic domain in which different mechanisms for maintaining data consistency under concurrent operations can be compared.

---

# 2. Research Goal

The main research question is:

> How do different synchronization mechanisms affect correctness and performance when multiple payment operations for the same order are executed concurrently?

The application provides the domain model.

The benchmark provides concurrent load.

The research compares several ways of protecting the same payment operation.

The experiment should evaluate:

* correctness;
* prevention of overpayment;
* prevention of lost updates;
* transaction conflicts;
* retries;
* execution time;
* throughput;
* latency under different levels of contention.

The project should remain compact. Additional complexity should primarily belong to the **concurrency experiment**, not to unrelated application functionality.

---

# 3. Current Project State

The repository is currently a **Symfony application skeleton**, not a working SplitPay application.

According to the current project analysis:

### Already exists

* Symfony 8.1;
* PHP >= 8.4;
* Doctrine ORM;
* PostgreSQL 16;
* Docker Compose environment;
* `User`, `Order`, `Payment` entities;
* Doctrine repositories;
* database migrations;
* basic controllers;
* basic HTTP tests.

### Does not exist yet

* payment business logic;
* application services;
* payment strategies;
* concurrency handling;
* benchmark;
* concurrency tests;
* real payment API;
* Vue frontend;
* DTOs;
* request validation;
* authentication/authorization.

The existing controllers are generated Symfony controller stubs and should not be treated as implemented application functionality.

---

# 4. Existing Technology Stack

The current project uses:

| Component           | Version / State     |
| ------------------- | ------------------- |
| PHP                 | >= 8.4              |
| Symfony             | 8.1.*               |
| Doctrine ORM        | ^3.7                |
| DoctrineBundle      | ^3.3                |
| Doctrine Migrations | ^4.0                |
| PostgreSQL          | 16                  |
| Docker              | Docker Compose      |
| Frontend            | not implemented yet |
| API Platform        | not installed       |
| Serializer          | not installed       |
| Validator           | not installed       |
| SecurityBundle      | not installed       |
| Messenger           | not installed       |
| Lock                | not installed       |
| Redis               | not used            |
| RabbitMQ            | not used            |

Do not assume that a Symfony component is installed just because Symfony supports it.

Before using a new component, check the current dependencies and install it only when it is actually required.

---

# 5. Docker Environment

The application is developed and executed through Docker.

Current containers:

```text
php       PHP 8.4 FPM application container
nginx     web server
database  PostgreSQL 16
```

The PHP application directory is mounted into the PHP container:

```text
./ → /var/www/html
```

Nginx exposes the application on:

```text
http://localhost:8080
```

PostgreSQL is available internally to the application as:

```text
database:5432
```

The database connection is configured through `DATABASE_URL`.

---

# 6. Important Docker Rule

Commands that require PHP, Composer or Symfony should normally be executed **inside the PHP container**.

Use:

```bash
docker compose exec php php bin/console ...
```

instead of assuming that PHP/Symfony CLI is installed on the host.

Examples:

```bash
docker compose exec php php bin/console about

docker compose exec php php bin/console debug:router

docker compose exec php php bin/console debug:container

docker compose exec php php bin/console lint:container
```

Composer:

```bash
docker compose exec php composer require ...
```

PHPUnit:

```bash
docker compose exec php php bin/phpunit
```

Start the environment:

```bash
docker compose up -d
```

Stop it:

```bash
docker compose down
```

View logs:

```bash
docker compose logs -f php
docker compose logs -f nginx
docker compose logs -f database
```

Open a shell inside the PHP container when needed:

```bash
docker compose exec php bash
```

If `bash` is unavailable:

```bash
docker compose exec php sh
```

---

# 7. Current Project Structure

The important current structure is approximately:

```text
splitpay/
├── AGENTS.md
├── CLAUDE.md
├── composer.json
├── composer.lock
├── symfony.lock
│
├── Dockerfile
├── compose.yaml
├── compose.override.yaml
└── docker/
    └── nginx/
        └── default.conf
│
├── config/
│   ├── bundles.php
│   ├── services.yaml
│   ├── routes.yaml
│   └── packages/
│
├── migrations/
│   ├── Version20260918162837.php
│   └── Version20260920113751.php
│
├── public/
│   └── index.php
│
├── src/
│   ├── Controller/
│   │   ├── OrderController.php
│   │   └── UserController.php
│   │
│   ├── Entity/
│   │   ├── Order.php
│   │   ├── Payment.php
│   │   └── User.php
│   │
│   └── Repository/
│       ├── OrderRepository.php
│       ├── PaymentRepository.php
│       └── UserRepository.php
│
└── tests/
    └── Controller/
        ├── OrderControllerTest.php
        └── UserControllerTest.php
```

This is the **current structure**, not the final structure.

New directories should be created only when their functionality is introduced.

---

# 8. Current Domain Model

The current entities are:

```text
User
Order
Payment
```

Current relationships are:

```text
User 1 ───── N Order
User 1 ───── N Payment

Order 1 ──── N Payment
```

`Order.user` currently represents the owner/creator of the order.

`Payment.user` represents the user who made the payment.

The final research model does not require a separate participant entity.

A user participates in an order by having one or more payments associated with that order.

---

# 9. Current Entity State

The current entities are basic Doctrine entities.

They contain getters/setters and relationship synchronization but do not contain meaningful business logic.

## User

Current fields:

```text
id
username
orders
payments
```

## Order

Current fields:

```text
id
full_price
paid_price
user
payments
```

Important:

```text
full_price     → currently FLOAT / DOUBLE
paid_price     → currently FLOAT / DOUBLE
```

This must be changed before implementing the final payment logic.

Money should use integer minor units.

For example:

```text
$10.50 → 1050
```

Target fields:

```text
full_price_cents
paid_price_cents
```

## Payment

Current fields:

```text
id
paid_amount
user
order
```

Target payment model should use:

```text
amount_cents
payment_method
idempotency_key
created_at
```

---

# 10. Important Existing Database Issue

The current migrations and entity mappings have a table-name mismatch.

The migrations create:

```text
"user"
"order"
"payment"
```

while the entity mapping currently uses plural names for some entities, including:

```text
users
orders
payments
```

`order` is also an SQL reserved word, which is why the entity currently explicitly maps it.

Before building further functionality, verify the schema:

```bash
docker compose exec php php bin/console doctrine:schema:validate
```

The final project should use one consistent naming scheme.

Prefer explicit safe table names:

```text
users
orders
payments
```

and generate a migration that brings the existing database into the desired state.

Do not use:

```bash
doctrine:schema:update
```

Schema changes must be represented by Doctrine migrations.

---

# 11. Target Domain Model

The target model is:

```text
User
├── payments ───────────────┐
│                           │
└── orders                  │
                            ▼
Order ─────── payments ─── Payment
```

The important business relationship is:

```text
Order 1 ───── N Payment
Payment N ─── 1 User
```

One order can therefore have:

```text
Order #1
├── Payment $40 → User A → card
├── Payment $30 → User B → cash
└── Payment $30 → User C → card
```

---

# 12. Target User Entity

Minimal target model:

```text
User
- id
- name
- created_at
```

Authentication is not required for the research.

Do not introduce a complete user-management system unless a later requirement specifically requires it.

---

# 13. Target Order Entity

Target model:

```text
Order
- id
- name
- full_price_cents
- paid_price_cents
- status
- version
- created_at
- updated_at
```

### Order status

```text
open
paid
cancelled
```

`version` is required for the optimistic-locking experiment.

`paid_price_cents` is a denormalized value.

It should represent the current amount paid and be compared against the aggregate of payments when verifying consistency.

---

# 14. Target Payment Entity

Target model:

```text
Payment
- id
- order
- user
- amount_cents
- payment_method
- idempotency_key
- created_at
```

Payment methods:

```text
card
cash
```

Use an enum for payment method.

The payment amount must be stored as an integer minor unit.

---

# 15. Core Invariants

The strategies must solve the same business problem.

## I1 — No overpayment

```text
SUM(Payment.amount_cents) <= Order.full_price_cents
```

The total amount of successful payments must never exceed the order total.

## I2 — Paid amount consistency

```text
Order.paid_price_cents == SUM(Payment.amount_cents)
```

The benchmark and tests should be able to verify this invariant.

## I3 — Payment cannot exceed remaining amount

```text
payment.amount_cents <=
Order.full_price_cents - Order.paid_price_cents
```

The exact implementation depends on the synchronization strategy.

## I4 — No lost update

Concurrent successful payments must not overwrite one another.

## I5 — Idempotency

The same `idempotency_key` must not create multiple payments.

Idempotency is part of the application correctness model but is not the primary experimental variable.

---

# 16. Source of Truth

`Payment` records represent the actual individual payments.

`Order.paid_price_cents` is a denormalized aggregate maintained for efficient access.

Therefore consistency can be checked with:

```sql
SELECT SUM(amount_cents)
FROM payments
WHERE order_id = :orderId;
```

and compared with:

```text
orders.paid_price_cents
```

The project should not silently allow these values to diverge.

The benchmark should explicitly detect such divergence.

---

# 17. Payment Processing Operation

All synchronization strategies represent the same logical operation:

```text
processPayment(
    order,
    user,
    amount,
    paymentMethod,
    idempotencyKey
)
```

Conceptual workflow:

```text
1. Validate input.
2. Check idempotency.
3. Determine available amount.
4. Apply synchronization mechanism.
5. Create Payment.
6. Increase Order.paid_price_cents.
7. Mark Order as paid if appropriate.
8. Commit transaction.
```

The synchronization mechanism is the main difference between strategies.

Business semantics should remain as similar as practical.

---

# 18. Synchronization Strategies

The research compares five strategies.

## Strategy 1 — Baseline

No explicit synchronization.

Conceptually:

```text
read order
→ calculate remaining amount
→ create payment
→ update paid amount
```

This provides the baseline and should expose race conditions under concurrent load.

---

## Strategy 2 — Pessimistic Lock

Use a database row lock.

Conceptually:

```sql
SELECT ...
FROM orders
WHERE id = :orderId
FOR UPDATE;
```

Doctrine:

```text
LockMode::PESSIMISTIC_WRITE
```

The order row remains locked during the critical operation.

---

## Strategy 3 — Optimistic Lock

Use Doctrine optimistic locking with the `version` field.

Concurrent modifications should result in a conflict instead of silently overwriting data.

A limited retry mechanism may be used.

The benchmark should measure:

* conflicts;
* retries;
* successful operations;
* execution time.

---

## Strategy 4 — Application Mutex

Use Symfony Lock.

The lock key should be based on the order:

```text
order:{orderId}
```

The initial implementation should use a simple lock backend and should not introduce Redis merely for this purpose.

If `symfony/lock` is not installed, install it when implementing this phase:

```bash
docker compose exec php composer require symfony/lock
```

---

## Strategy 5 — Atomic Conditional UPDATE

Use one atomic database operation to reserve the required amount.

Conceptually:

```sql
UPDATE orders
SET paid_price_cents = paid_price_cents + :amount
WHERE id = :orderId
  AND paid_price_cents + :amount <= full_price_cents
```

Then check affected rows.

```text
1 row → amount successfully reserved
0 rows → payment rejected
```

The implementation must ensure that the resulting `Payment` record remains consistent with the atomic update.

---

# 19. Strategy Abstraction

The strategies should share a common interface.

Conceptually:

```text
PaymentStrategyInterface
    processPayment(...)
```

The exact method signature may be adjusted during implementation.

The important requirement is that the benchmark can select a strategy without duplicating the whole application.

---

# 20. Target Backend Architecture

Target structure:

```text
src/
├── Controller/
│   ├── OrderController.php
│   └── PaymentController.php
│
├──
```
