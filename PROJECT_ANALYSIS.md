# SplitPay — Анализ проекта (Актуальное состояние кодовой базы)

> **Назначение документа:** предоставить разработчику или AI-агенту полную, точную и актуальную картину текущего состояния проекта **SplitPay** — архитектуру, стек, доменную модель, стратегии конкурентности, API, бенчмарки и инфраструктуру.

---

## 1. Общее резюме

**SplitPay** — это высоконадежное приложение на **Symfony 8.1** и **PHP 8.4** с подключенным **Doctrine ORM 3.7** и **PostgreSQL 16**, разработанное для изучения и сравнительного анализа механизмов синхронизации при конкурентных финансовых операциях над общим заказом.

Архитектура проекта полностью реализована и протестирована:
- **Доменная модель:** Сущности `User`, `Order`, `Payment` со строгими инвариантами, целочисленным учетом денежных величин в минорных единицах (`amountCents`, `fullPriceCents`, `paidPriceCents`) и перечислениями (`OrderStatus`, `PaymentMethod`).
- **5 стратегий конкурентности:** Реализованы по паттерну Strategy (`baseline`, `pessimistic`, `optimistic`, `mutex`, `atomic`).
- **REST API:** Полноценный презентационный слой на тонких контроллерах с валидацией через `#[MapRequestPayload]` и DTO.
- **Инструментарий бенчмаркинга:** Консольная команда `app:benchmark:run`, многопоточный runner на `curl_multi` и независимый SQL-аудитор целостности `ConsistencyAuditor`.
- **Автоматические тесты:** 47 тестов (100% passing), включая интеграционные тесты стратегий в реальной PostgreSQL и стресс-тесты параллельных HTTP-запросов через Nginx к PHP-FPM.

---

## 2. Стек и установленные зависимости

Спецификация из `composer.json` и `symfony.lock`:

| Компонент / Пакет | Версия | Назначение |
|---|---|---|
| **PHP** | `>= 8.4` (в контейнере 8.4.25) | Современные возможности: Typed Constants, Property Hooks, Constructor Promotion |
| **Symfony Framework Bundle** | `8.1.*` | Ядро приложения, MicroKernel |
| **Symfony Console** | `8.1.*` | CLI команды управления и бенчмаркинга |
| **Symfony Serializer** | `8.1.*` | Преобразование JSON ↔ DTO |
| **Symfony Validator** | `8.1.*` | Валидация входных данных DTO (`#[Assert\...]`) |
| **Symfony Lock** | `8.1.*` | Распределенные мьютексы (`LockFactory`) для стратегии `mutex` |
| **Symfony Dotenv & Yaml** | `8.1.*` | Загрузка конфигурации и переменных окружения |
| **Doctrine ORM** | `^3.7` | Объектно-реляционное отображение, `#[ORM\Version]` |
| **Doctrine Bundle** | `^3.3` | Интеграция Doctrine в Symfony DI |
| **Doctrine Migrations Bundle** | `^4.0` | Управление версионированием схемы БД |
| **PostgreSQL** | `16` (Alpine) | Реляционная СУБД, поддержка row-level locks и atomic conditional update |
| **PHPUnit** (dev) | `^13.4` | Модульное, интеграционное и стресс-тестирование |
| **Symfony BrowserKit & CssSelector** | `8.1.*` | Функциональное тестирование HTTP API (`WebTestCase`) |

---

## 3. Архитектура и структура директорий

Кодовая база структурирована по слоям (Clean Architecture / DDD-lite):

```text
splitpay/
├── bin/
│   ├── console                      # CLI Symfony
│   └── phpunit                      # Раннер тестов
├── config/
│   ├── packages/                    # Конфигурация framework, doctrine, cache, lock
│   ├── routes.yaml                  # Автоподключение маршрутов из атрибутов
│   └── services.yaml                # autowire/autoconfigure для App
├── docker/
│   └── nginx/default.conf           # Конфигурация Nginx: FastCGI к php:9000
├── migrations/
│   ├── Version20260918162837.php    # Первоначальная миграция
│   ├── Version20261007193107.php    # Промежуточная схема
│   └── Version20261102130644.php    # Переход на центы, индексы, idempotency_key, version
├── src/
│   ├── Benchmark/                   # Инфраструктура бенчмарка и аудита
│   │   ├── AuditResult.php          # DTO результата SQL-аудита
│   │   ├── BenchmarkResult.php      # DTO результатов прогона (RPS, латентность, коды ответов)
│   │   ├── BenchmarkRunner.php      # Асинхронный генератор нагрузки на curl_multi
│   │   └── ConsistencyAuditor.php   # Независимый верификатор согласованности данных в БД
│   ├── Command/
│   │   └── BenchmarkRunCommand.php  # Консольная команда app:benchmark:run
│   ├── Controller/
│   │   ├── OrderController.php      # POST /orders, GET /orders/{id}
│   │   ├── PaymentController.php    # POST /payments/{orderId}, GET /payments/{id}
│   │   └── UserController.php       # POST /users, GET /users/{id}
│   ├── DTO/
│   │   ├── Order/CreateOrderRequest.php
│   │   ├── Payment/ProcessPaymentRequest.php
│   │   └── User/CreateUserRequest.php
│   ├── Entity/
│   │   ├── Order.php                # Заказ (полная цена, оплачено, статус, версия)
│   │   ├── Payment.php              # Платеж (сумма, метод, ключ идемпотентности, дата)
│   │   └── User.php                 # Пользователь/плательщик
│   ├── Enum/
│   │   ├── OrderStatus.php          # open | paid | cancelled
│   │   └── PaymentMethod.php        # card | cash
│   ├── Exception/
│   │   ├── IdempotencyConflictException.php       # HTTP 409
│   │   ├── OptimisticLockExhaustedException.php   # HTTP 409
│   │   ├── OrderNotOpenException.php              # HTTP 409
│   │   └── OverpaymentException.php               # HTTP 422
│   ├── Payment/
│   │   ├── PaymentResult.php
│   │   ├── PaymentStrategyInterface.php           # Единый контракт стратегий
│   │   ├── StrategyRegistry.php                   # DI-реестр стратегий (#[AutowireIterator])
│   │   └── Strategy/
│   │       ├── AtomicConditionalUpdateStrategy.php # Стратегия 5: Атомарный SQL UPDATE
│   │       ├── BaselineStrategy.php               # Стратегия 1: Без блокировок (контрольная)
│   │       ├── OptimisticLockStrategy.php         # Стратегия 3: #[ORM\Version] + Retry
│   │       ├── PessimisticLockStrategy.php        # Стратегия 2: SELECT ... FOR UPDATE
│   │       └── SymfonyLockStrategy.php            # Стратегия 4: Мьютекс на уровне приложения
│   ├── Repository/
│   │   ├── OrderRepository.php
│   │   ├── PaymentRepository.php
│   │   └── UserRepository.php
│   ├── Service/
│   │   ├── OrderService.php
│   │   ├── PaymentService.php       # Оркестратор проверки существования и делегирования
│   │   └── UserService.php
│   └── Kernel.php
└── tests/                           # 47 автоматических тестов
    ├── Benchmark/ConsistencyAuditorTest.php
    ├── Command/BenchmarkRunCommandTest.php
    ├── Controller/OrderControllerTest.php
    ├── Controller/PaymentControllerTest.php
    ├── Controller/UserControllerTest.php
    ├── Payment/ConcurrencyStressTest.php
    ├── Payment/Strategy/StrategyIntegrationTest.php
    └── Service/PaymentServiceTest.php
```

---

## 4. Доменная модель и бизнес-инварианты

### Сущности (Таблицы PostgreSQL)

1. **`User` (таблица `users`):**
   - `id` (int, PK)
   - `name` (string, 2–255 символов)
   - `createdAt` (DateTimeImmutable)
   - Связи: `orders` (OneToMany), `payments` (OneToMany)

2. **`Order` (таблица `orders`):**
   - `id` (int, PK)
   - `name` (string)
   - `fullPriceCents` (int, положительное целое) — общая стоимость заказа
   - `paidPriceCents` (int) — денормализованная сумма подтвержденных платежей
   - `status` (OrderStatus: `open`, `paid`, `cancelled`)
   - `version` (int, `#[ORM\Version]`) — версионный счетчик для оптимистической блокировки
   - `createdAt`, `updatedAt` (DateTimeImmutable)
   - `user_id` (ManyToOne, владелец заказа)
   - Методы: `getRemainingCents()`, `isFullyPaid()`, `canAcceptPayment()`

3. **`Payment` (таблица `payments`):**
   - `id` (int, PK)
   - `amountCents` (int, > 0)
   - `paymentMethod` (PaymentMethod: `card`, `cash`)
   - `idempotencyKey` (string, max 255, **UNIQUE INDEX**) — глобальный ключ идемпотентности
   - `createdAt` (DateTimeImmutable)
   - `user_id` (ManyToOne, плательщик)
   - `order_id` (ManyToOne, оплачиваемый заказ)

### Бизнес-инварианты (Core Research Invariants)

- **I1 (No Overpayment):** Сумма всех успешных платежей заказа никогда не должна превышать `order.fullPriceCents`.
- **I2 (Data Consistency):** Поле `order.paidPriceCents` обязано строго равняться реальной сумме `SUM(payments.amountCents)` в БД.
- **I3 (Balance Check):** Платеж принимается только если `amountCents <= remainingCents` и заказ находится в статусе `open`.
- **I4 (No Lost Updates):** Параллельные транзакции не должны перезаписывать результаты друг друга.
- **I5 (Global Idempotency):** Повторная отправка запроса с тем же `idempotencyKey` не приводит к повторному списанию и возвращает `409 Conflict`.

---

## 5. Стратегии конкурентности (Payment Strategies)

Все стратегии реализуют общий интерфейс `PaymentStrategyInterface` и автоматически собираются через тег `app.payment_strategy` в `StrategyRegistry`.

| Идентификатор | Класс | Механизм синхронизации | Уровень защиты | Особенности поведения |
|---|---|---|---|---|
| **`baseline`** | `BaselineStrategy` | Отсутствует | ❌ Нет (Контрольная группа) | Read-then-write без блокировок. При конкурентности демонстрирует race condition, overpayment и потерю обновлений. |
| **`pessimistic`** | `PessimisticLockStrategy` | `LockMode::PESSIMISTIC_WRITE` | ✅ База данных (Row Lock) | Выполняет `SELECT ... FOR UPDATE`. Очередь запросов сериализуется на уровне строки PostgreSQL. |
| **`optimistic`** | `OptimisticLockStrategy` | `#[ORM\Version]` + Retry | ✅ Слой ORM (Doctrine) | Проверяет совпадение версии при `flush()`. При конфликте ловит `OptimisticLockException`, сбрасывает Identity Map и делает до 3 попыток. |
| **`mutex`** | `SymfonyLockStrategy` | `Symfony\Component\Lock` | ✅ Уровень приложения | Захватывает мьютекс `order:{id}` через `LockFactory` (flock/redis), открывает транзакцию и принудительно вызывает `$em->refresh($order)` для исключения застарелого чтения. |
| **`atomic`** | `AtomicConditionalUpdateStrategy` | Условный атомарный SQL `UPDATE` | ✅ Ядро СУБД (SQL Statement) | Выполняет атомарный `UPDATE orders SET paid_price_cents = paid_price_cents + :amount WHERE id = :id AND status = 'open' AND paid_price_cents + :amount <= full_price_cents`. Максимальный RPS. |

---

## 6. HTTP API и маршрутизация

Все эндпоинты типизированы, валидируются через DTO и возвращают JSON:

```text
POST /users                 Создание пользователя (201 Created)
GET  /users/{id}            Просмотр пользователя (200 OK / 404)
POST /orders                Создание заказа (201 Created)
GET  /orders/{id}           Просмотр заказа с балансом и статусом (200 OK / 404)
POST /payments/{orderId}    Проведение платежа по выбранной стратегии (201 Created)
GET  /payments/{id}         Просмотр записи платежа (200 OK / 404)
```

### Коды ошибок API:
- **`404 Not Found`**: Не существует указанный `userId`, `orderId` или `paymentId`.
- **`409 Conflict`**: Заказ закрыт (`OrderNotOpenException`), дубликат ключа идемпотентности (`IdempotencyConflictException`), либо исчерпан лимит повторов оптимистической блокировки (`OptimisticLockExhaustedException`).
- **`422 Unprocessable Entity`**: Ошибка валидации DTO (невалидный email, отрицательная сумма, некорректный enum) или попытка переплаты сверх остатка (`OverpaymentException`).

---

## 7. Бенчмаркинг и верификация (Benchmark & Auditing)

Проект содержит встроенный модуль экспериментального нагрузочного тестирования:

- **Консольная команда:** `php bin/console app:benchmark:run`
  - Параметры: `--strategy` (`baseline`, `pessimistic`, `optimistic`, `mutex`, `atomic`, `all`), `--requests`, `--concurrency`, `--order-full-price`, `--payment-amount`, `--format` (`table`, `markdown`, `json`).
- **`BenchmarkRunner`:** Создает сценарий и параллельно отправляет пачки запросов через `curl_multi` в Nginx. Рассчитывает время, RPS, распределение кодов и перцентили задержек (`P50`, `P95`, `P99`).
- **`ConsistencyAuditor`:** Подключается напрямую к БД и выполняет независимый SQL-аудит:
  - Сравнивает `orders.paid_price_cents` и `SUM(payments.amount_cents)`.
  - Фиксирует факты рассинхронизации (`isConsistent`) и переплаты (`isOverpaid`).

---

## 8. Инфраструктура (Docker)

Окружение разворачивается через Docker Compose:

- **`php`:** PHP 8.4 FPM (`php:8.4-fpm`), расширения `pdo_pgsql`, `amqp`, `opcache`. Том проекта примонтирован в `/var/www/html`.
- **`nginx`:** Nginx 1.25+ Alpine, проксирует HTTP-трафик на порт 9000 PHP-FPM. Публикует порт **8080** на хосте.
- **`database`:** PostgreSQL 16 Alpine. Внутренний порт 5432, БД `splitpay`.

---

## 9. Автоматическое тестирование

Тестовый набор (`tests/`) включает **47 тестов (121 assertion)**:
1. `tests/Controller/`: Тесты эндпоинтов, валидации схем, кодов 200, 201, 404, 409, 422.
2. `tests/Payment/Strategy/StrategyIntegrationTest.php`: 22 интеграционных теста, проверяющих каждую стратегию изолированно в тестовой БД.
3. `tests/Payment/ConcurrencyStressTest.php`: 5 параллельных стресс-тестов на реальном пуле PHP-FPM, подтверждающих сбой `baseline` и абсолютную математическую согласованность `pessimistic`, `optimistic`, `mutex` и `atomic`.
4. `tests/Benchmark/`: Тестирование аудитора целостности и расчетов перцентилей.
5. `tests/Command/`: Проверка консольной команды бенчмарка.

---

## 10. Краткая памятка команд

```bash
# Запуск контейнеров
docker compose up -d

# Миграции БД
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Прогрев / очистка кеша
docker compose exec php php bin/console cache:clear
docker compose exec php php bin/console cache:warmup

# Запуск полного набора автотестов
docker compose exec php php bin/phpunit

# Запуск бенчмарка по всем стратегиям
docker compose exec php php bin/console app:benchmark:run --strategy all --requests 50 --concurrency 10
```
