# SplitPay — Анализ проекта (Актуальное состояние кодовой базы)

> **Назначение документа:** дать разработчику или AI-агенту полную, точную и актуальную картину проекта **SplitPay** — технологический стек, архитектуру слоёв, доменную модель, 5 стратегий конкурентности, механизмы Redis-идемпотентности и асинхронных событий RabbitMQ, веб-интерфейс (Twig), HTTP API, бенчмарк и тестовое покрытие.

---

## 1. Общее резюме

**SplitPay** — это прикладная исследовательская система на **Symfony 8.1.6** и **PHP 8.4**, использующая **PostgreSQL 16**, **Redis 7** и **RabbitMQ 3** в Docker Compose. Проект моделирует совместную оплату заказов (split payments) и служит платформой для эмпирического сравнения механизмов обеспечения консистентности данных при высокой конкурентности.

На текущий момент проект полностью реализован и включает:
- **Доменная модель:** Сущности `User`, `Order`, `Payment` с целочисленным учётом денежных величин в центах (`amountCents`, `fullPriceCents`, `paidPriceCents`), перечислениями (`OrderStatus`, `PaymentMethod`) и оптимистической версионностью (`#[ORM\Version]`).
- **5 стратегий конкурентности:** Реализованы через общий интерфейс `PaymentStrategyInterface` (`baseline`, `pessimistic`, `optimistic`, `mutex`, `atomic`) и автоматически регистрируются через `StrategyRegistry`.
- **Distributed Redis Layer:**
  - Распределённый pre-filter идемпотентности `RedisIdempotencyFilter` (Stripe pattern на базе `SET NX EX` в Redis до обращения к PostgreSQL).
  - Распределённый бэкенд `LOCK_DSN=redis://redis:6379` для `symfony/lock` (стратегия `mutex`).
- **Async Messaging (RabbitMQ + Symfony Messenger):** Асинхронная публикация доменных событий `PaymentReceivedEvent` и `OrderFullyPaidEvent` через AMQP exchange `splitpay_events`, политика повторных попыток (exponential backoff) и Dead Letter Queue (`splitpay_dlq`).
- **Два интерфейса взаимодействия:**
  - **REST API** (тонкие контроллеры, DTO с `#[MapRequestPayload]`, Symfony Validator/Serializer).
  - **Серверный веб-интерфейс (Twig + Bootstrap 5.3)** на русском/украинском языке (интерфейс на украинском согласно политике `AGENTS.md`): дашборд заказов, карточка заказа с оплатой и историей транзакций, интерактивный запуск бенчмарков.
- **Инструментарий бенчмаркинга:** CLI-команда `app:benchmark:run`, параллельный HTTP-раннер на `curl_multi` (`BenchmarkRunner`) и независимый SQL-аудитор согласованности БД (`ConsistencyAuditor`).
- **Автоматическое тестирование:** **62 теста (207 assertions)** — все проходят (`OK`).

---

## 2. Стек и установленные зависимости

Согласно `composer.json`, `symfony.lock` и `Dockerfile`:

| Компонент / Пакет | Версия / Образ | Назначение |
|---|---|---|
| **PHP** | `>= 8.4` (`php:8.4-fpm`, 8.4.27) | Расширения `pdo_pgsql`, `redis`, `amqp`, `opcache` |
| **Symfony Framework / Console / Runtime** | `8.1.*` (v8.1.6) | Ядро приложения, CLI, runtime |
| **Symfony Twig Bundle** | `8.1.*` | Серверный рендеринг веб-интерфейса (`templates/`) |
| **Symfony Serializer & PropertyAccess/Info** | `8.1.*` | JSON-сериализация и маппинг DTO (`#[MapRequestPayload]`) |
| **Symfony Validator** | `8.1.*` | Валидация DTO (`#[Assert\...]`) |
| **Symfony Lock** | `8.1.*` | Распределённые мьютексы (`LockFactory`) поверх Redis |
| **Symfony Messenger** | `8.1.*` | Шина сообщений и асинхронный воркер (AMQP / RabbitMQ) |
| **Doctrine ORM / Bundle / Migrations** | `^3.7` / `^3.3` / `^4.0` | ORM, маппинг атрибутами, версионирование и миграции БД |
| **PostgreSQL** | `postgres:16-alpine` | Основная реляционная СУБД (строковые блокировки, атомарные UPDATE) |
| **Redis** | `redis:7-alpine` | In-memory фильтр идемпотентности и хранилище распределённых блокировок |
| **RabbitMQ** | `rabbitmq:3-management-alpine` | Брокер сообщений (AMQP `5672`, Management UI `15672`) |
| **Nginx** | `nginx:alpine` | Веб-сервер на порту `8080`, проксирование FastCGI в `php:9000` |
| **PHPUnit** (dev) | `^13.4` | Юнит-, интеграционные, функциональные и стресс-тесты |
| **BrowserKit / CssSelector / MakerBundle** (dev) | `8.1.*` / `^1.68` | Тестирование веб-форм/DOM и генерация кода |

---

## 3. Архитектура и структура кодовой базы

```text
splitpay/
├── bin/
│   ├── console                                # CLI Symfony
│   └── phpunit                                # Запуск тестов
├── config/
│   ├── bundles.php                            # Framework, Doctrine, Migrations, Twig, Maker(dev)
│   ├── packages/
│   │   ├── cache.yaml
│   │   ├── doctrine.yaml                      # underscore naming, identity, _test суффикс
│   │   ├── doctrine_migrations.yaml
│   │   ├── framework.yaml
│   │   ├── lock.yaml                          # LOCK_DSN (%env(LOCK_DSN)%)
│   │   ├── messenger.yaml                     # AMQP async + failed DLQ (sync:// в test)
│   │   ├── property_info.yaml
│   │   ├── routing.yaml
│   │   ├── twig.yaml                          # strict_variables: true в test
│   │   └── validator.yaml
│   ├── routes.yaml                            # Импорт атрибутов #[Route] из src/Controller/
│   └── services.yaml                          # autowire/autoconfigure для App\
├── docker/nginx/default.conf
├── migrations/
│   ├── Version20260918162837.php
│   ├── Version20261007193107.php
│   └── Version20261102130644.php              # Финальная схема: центы, status, version, idempotency_key
├── src/
│   ├── Benchmark/
│   │   ├── AuditResult.php                    # DTO результата проверки целостности БД
│   │   ├── BenchmarkResult.php                # DTO метрик (RPS, P50/P95/P99, 201/409/422/5xx)
│   │   ├── BenchmarkRunner.php                # Многопоточный HTTP-раннер через curl_multi
│   │   └── ConsistencyAuditor.php             # Прямой SQL-аудит orders vs SUM(payments)
│   ├── Command/
│   │   └── BenchmarkRunCommand.php            # CLI app:benchmark:run
│   ├── Controller/
│   │   ├── OrderController.php                # API: POST /orders, GET /orders/{id}
│   │   ├── PaymentController.php              # API: POST /payments/{orderId}, GET /payments/{id}
│   │   ├── UserController.php                 # API: POST /users, GET /users/{id}
│   │   └── Web/
│   │       ├── DashboardController.php        # Web: GET /, GET /dashboard
│   │       ├── WebBenchmarkController.php     # Web: GET /benchmark, POST /benchmark/run
│   │       ├── WebOrderController.php         # Web: POST /web/orders, GET /web/orders/{id}, POST /web/orders/{id}/pay
│   │       └── WebUserController.php          # Web: POST /web/users
│   ├── DTO/
│   │   ├── Order/CreateOrderRequest.php
│   │   ├── Payment/ProcessPaymentRequest.php
│   │   └── User/CreateUserRequest.php
│   ├── Entity/
│   │   ├── Order.php
│   │   ├── Payment.php
│   │   └── User.php
│   ├── Enum/
│   │   ├── OrderStatus.php                    # open | paid | cancelled
│   │   └── PaymentMethod.php                  # card | cash
│   ├── Exception/
│   │   ├── IdempotencyConflictException.php   # 409 Conflict
│   │   ├── OptimisticLockExhaustedException.php # 409 Conflict
│   │   ├── OrderNotOpenException.php          # 409 Conflict
│   │   └── OverpaymentException.php           # 422 Unprocessable Entity
│   ├── Message/
│   │   ├── OrderFullyPaidEvent.php            # Событие полной оплаты заказа
│   │   └── PaymentReceivedEvent.php           # Событие регистрации платежа
│   ├── MessageHandler/
│   │   ├── SendOrderPaidWebhookHandler.php    # Логирует отправку webhook мерчанту
│   │   └── SendPaymentReceiptHandler.php      # Логирует отправку чека пользователю
│   ├── Payment/
│   │   ├── Idempotency/
│   │   │   ├── IdempotencyFilterInterface.php
│   │   │   └── RedisIdempotencyFilter.php     # Stripe-паттерн: acquire / commit / release в Redis
│   │   ├── Strategy/
│   │   │   ├── AtomicConditionalUpdateStrategy.php
│   │   │   ├── BaselineStrategy.php
│   │   │   ├── OptimisticLockStrategy.php
│   │   │   ├── PessimisticLockStrategy.php
│   │   │   └── SymfonyLockStrategy.php
│   │   ├── PaymentResult.php
│   │   ├── PaymentStrategyInterface.php       # #[AutoconfigureTag('app.payment_strategy')]
│   │   └── StrategyRegistry.php
│   ├── Repository/
│   │   ├── OrderRepository.php
│   │   ├── PaymentRepository.php
│   │   └── UserRepository.php
│   ├── Service/
│   │   ├── OrderService.php
│   │   ├── PaymentService.php                 # Оркестрация: Redis filter -> DB strategy -> RabbitMQ
│   │   └── UserService.php
│   └── Kernel.php
├── templates/
│   ├── base.html.twig                         # Bootstrap 5.3 + навигация + модалки создания
│   ├── benchmark/index.html.twig              # Живой запуск и визуализация метрик стратегий
│   ├── dashboard/index.html.twig              # Сводная статистика и список заказов
│   └── order/show.html.twig                   # Детали заказа, прогресс-бар, форма оплаты, платежи
└── tests/                                     # 62 теста (207 assertions)
```

---

## 4. Доменная модель и бизнес-инварианты

### Сущности БД (PostgreSQL 16)

1. **`User` (таблица `users`):**
   - `id` (int, PK, identity)
   - `name` (string, 2–255 символов)
   - `createdAt` (DateTimeImmutable)
   - Связи: `orders` (`OneToMany`), `payments` (`OneToMany`)

2. **`Order` (таблица `orders`):**
   - `id` (int, PK, identity)
   - `name` (string, 2–255 символов)
   - `fullPriceCents` (int, > 0) — полная стоимость заказа в центах
   - `paidPriceCents` (int, >= 0) — денормализованная сумма подтверждённых платежей
   - `status` (`OrderStatus`: `open`, `paid`, `cancelled`)
   - `version` (int, `#[ORM\Version]`) — счётчик версии для оптимистической блокировки
   - `createdAt`, `updatedAt` (DateTimeImmutable)
   - `user` (`ManyToOne`, NOT NULL) — владелец заказа
   - Доменные методы: `getRemainingCents()`, `isFullyPaid()`, `canAcceptPayment()`

3. **`Payment` (таблица `payments`):**
   - `id` (int, PK, identity)
   - `amountCents` (int, > 0) — сумма платежа в центах
   - `paymentMethod` (`PaymentMethod`: `card`, `cash`)
   - `idempotencyKey` (string, <= 255, **UNIQUE INDEX**) — глобальный ключ идемпотентности
   - `createdAt` (DateTimeImmutable)
   - `user` (`ManyToOne`, NOT NULL) — плательщик
   - `order` (`ManyToOne`, NOT NULL) — заказ

### Инварианты согласованности (I1–I5)

- **I1 (No Overpayment):** Сумма успешных платежей по заказу не превышает `order.fullPriceCents`.
- **I2 (Data Consistency):** Поле `order.paidPriceCents` строго равно `SUM(payments.amountCents)`.
- **I3 (Balance Check):** Платёж принимается только при статусе `open` и `amountCents <= remainingCents`.
- **I4 (No Lost Updates):** Параллельные успешные платежи не перезаписывают обновления друг друга.
- **I5 (Global Idempotency):** Повторный запрос с тем же `idempotencyKey` отклоняется с `409 Conflict` без повторного проведения.

---

## 5. Поток обработки платежа (Redis + Strategy + RabbitMQ)

Оркестрация выполняется в [`PaymentService::pay()`](file:///\\wsl.localhost\Ubuntu\home\c959\projects\splitpay\src\Service\PaymentService.php):

```text
HTTP POST /payments/{orderId}  (или POST /web/orders/{id}/pay)
  │
  ▼
1. RedisIdempotencyFilter::acquire(key)
   └─ SET idempotency:{key} "processing" NX EX 60
   └─ Если ключ уже существует → немедленный HTTP 409 Conflict (без обращения к PostgreSQL)
  │
  ▼
2. Загрузка Order и User (404 если отсутствуют; при ошибке ключ в Redis освобождается)
  │
  ▼
3. Выполнение выбранной PaymentStrategyInterface внутри транзакции PostgreSQL
   ├─ При UniqueConstraintViolationException → IdempotencyConflictException (commit в Redis + 409)
   └─ При Overpayment / OrderNotOpen / иной ошибке → release(key) в Redis (можно повторить)
  │
  ▼
4. RedisIdempotencyFilter::commit(key, paymentId)
   └─ SET idempotency:{key} "completed" EX 86400 (хранение 24 часа)
  │
  ▼
5. Асинхронная отправка событий в RabbitMQ (MessageBusInterface::dispatch)
   ├─ PaymentReceivedEvent (всегда при успешной оплате)
   └─ OrderFullyPaidEvent  (если заказ перешёл в состояние полной оплаты)
```

### Стратегии синхронизации (`src/Payment/Strategy/`)

| Стратегия | Класс | Механизм | Уровень | Поведение и особенности |
|---|---|---|---|---|
| **`baseline`** | `BaselineStrategy` | Без блокировок | ❌ Нет защиты | Контрольная группа. Read-then-write без лока приводит к race condition, lost updates и переплате. |
| **`pessimistic`** | `PessimisticLockStrategy` | `SELECT ... FOR UPDATE` (`LockMode::PESSIMISTIC_WRITE`) | ✅ БД (строка) | Перечитывает заказ с эксклюзивной блокировкой строки. Очередь запросов выстраивается в PostgreSQL. Ловит `UniqueConstraintViolationException`. |
| **`optimistic`** | `OptimisticLockStrategy` | `#[ORM\Version]` + retry | ✅ ORM | При конфликте версий откатывает транзакцию, вызывает `ManagerRegistry::resetManager()` и повторяет попытку (до `MAX_RETRIES = 3`), иначе кидает `OptimisticLockExhaustedException` (409). |
| **`mutex`** | `SymfonyLockStrategy` | `symfony/lock` (`LOCK_DSN=redis://redis:6379`) | ✅ Приложение (Redis) | Захватывает распределённый мьютекс `order:{id}` (TTL 10s), открывает транзакцию и **принудительно обновляет сущность через `$em->refresh($order)`** после получения лока. Ловит `UniqueConstraintViolationException`. |
| **`atomic`** | `AtomicConditionalUpdateStrategy` | Одиночный условный SQL `UPDATE` | ✅ Ядро БД | Выполняет проверку остатка, инкремент `paid_price_cents`, инкремент `version` и смену статуса `open -> paid` одним SQL-запросом без ручных блокировок. Максимальный RPS. |

---

## 6. Асинхронный обмен сообщениями (RabbitMQ + Messenger)

Конфигурация в [`config/packages/messenger.yaml`](file:///\\wsl.localhost\Ubuntu\home\c959\projects\splitpay\config\packages\messenger.yaml):

- **Транспорт `async`:** `MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f/messages`
  - Exchange: `splitpay_events` (`fanout`), очередь: `splitpay_async` (`auto_setup: true`).
  - Retry-стратегия: `max_retries: 3`, экспоненциальная задержка (`delay: 1000`, `multiplier: 2`, `max_delay: 10000` → 1s → 2s → 4s).
- **Транспорт `failed` (DLQ):**
  - Exchange: `splitpay_dlq` (`fanout`), очередь: `splitpay_failed`.
- **Обработчики (`src/MessageHandler/`):**
  - `SendPaymentReceiptHandler` ← `PaymentReceivedEvent` (имитация отправки чека плательщику, логирование через `LoggerInterface`).
  - `SendOrderPaidWebhookHandler` ← `OrderFullyPaidEvent` (имитация исходящего webhook мерчанту).
- **Окружение `test`:** Транспорты `async` и `failed` переопределены на `sync://`, поэтому тесты не зависят от запущенного воркера и очереди RabbitMQ.

---

## 7. HTTP API и Веб-интерфейс (Маршруты)

Полный список маршрутов (по `bin/console debug:router`):

### REST API (JSON)

| Метод | Путь | Имя маршрута | Контроллер | Назначение |
|---|---|---|---|---|
| `POST` | `/users` | `user_create` | `UserController::store` | Создание пользователя (`CreateUserRequest` → `201`) |
| `GET` | `/users/{id}` | `user_show` | `UserController::show` | Получение пользователя (`200` / `404`) |
| `POST` | `/orders` | `order_create` | `OrderController::store` | Создание заказа (`CreateOrderRequest` → `201` / `404`) |
| `GET` | `/orders/{id}` | `order_show` | `OrderController::show` | Информация о заказе с `remainingCents`, `status`, `version` |
| `POST` | `/payments/{orderId}` | `payment_create` | `PaymentController::create` | Проведение платежа (`ProcessPaymentRequest` → `201` / `404` / `409` / `422`) |
| `GET` | `/payments/{id}` | `payment_show` | `PaymentController::show` | Информация о платеже (`200` / `404`) |

### Веб-интерфейс (Twig + Bootstrap 5.3, украинский язык)

| Метод | Путь | Имя маршрута | Контроллер | Назначение |
|---|---|---|---|---|
| `GET` | `/` и `/dashboard` | `web_dashboard` / `web_dashboard_alias` | `DashboardController::index` | Дашборд со сводной статистикой и таблицей заказов |
| `POST` | `/web/users` | `web_user_create` | `WebUserController::create` | Создание пользователя через модальную форму |
| `POST` | `/web/orders` | `web_order_create` | `WebOrderController::create` | Создание заказа через модальную форму |
| `GET` | `/web/orders/{id}` | `web_order_show` | `WebOrderController::show` | Карточка заказа, прогресс, история платежей, форма оплаты |
| `POST` | `/web/orders/{id}/pay` | `web_order_pay` | `WebOrderController::pay` | Проведение платежа из UI с выбором стратегии и flash-статусом |
| `GET` | `/benchmark` | `web_benchmark` | `WebBenchmarkController::index` | Страница интерактивного бенчмаркинга |
| `POST` | `/benchmark/run` | `web_benchmark_run` | `WebBenchmarkController::run` | Живой запуск `BenchmarkRunner` и визуализация P50/P95/P99/RPS и аудита |

---

## 8. Инфраструктура (Docker Compose)

Сервисы в [`compose.yaml`](file:///\\wsl.localhost\Ubuntu\home\c959\projects\splitpay\compose.yaml):

| Сервис | Образ / Сборка | Порты на хосте | Назначение |
|---|---|---|---|
| **`php`** | `Dockerfile` (`php:8.4-fpm` + `pdo_pgsql`, `redis`, `amqp`) | `9000` (внутр.) | Обработка PHP-FPM запросов, выполнение CLI и Messenger-воркеров |
| **`nginx`** | `nginx:alpine` | `8080 -> 80` | Веб-сервер для API и Twig UI (`http://localhost:8080`) |
| **`database`** | `postgres:16-alpine` | `5432 -> 5432` | Основная БД `splitpay` (и `splitpay_test` для PHPUnit) |
| **`redis`** | `redis:7-alpine` | `6379 -> 6379` | Хранилище для `RedisIdempotencyFilter` и `symfony/lock` |
| **`rabbitmq`** | `rabbitmq:3-management-alpine` | `5672 -> 5672`, `15672 -> 15672` | AMQP брокер + Management UI (`http://localhost:15672`, `guest`/`guest`) |

---

## 9. Тестирование и валидация

Статус по итогам прогона `docker compose exec php php bin/phpunit`:
- **Всего тестов:** **62** (`207 assertions`) — **100% проходят** (присутствуют 2 минорных предупреждения PHPUnit 13 по использованию `createMock()` вместо `createStub()`).
- **Валидация Symfony:** `lint:container`, `lint:twig templates/`, `lint:yaml config/` и `doctrine:schema:validate` — **все [OK]**.

Разбивка тестового набора (`tests/`):
- `tests/Controller/`: `UserControllerTest`, `OrderControllerTest`, `PaymentControllerTest` (JSON API), `Web/WebInterfaceTest` (Twig UI, редиректы, flash-сообщения, конфликты и переплаты).
- `tests/Payment/Strategy/StrategyIntegrationTest.php`: 22 теста всех 5 стратегий против реальной БД `splitpay_test` (включая проверку refresh устаревшего состояния для `mutex`).
- `tests/Payment/ConcurrencyStressTest.php`: 5 параллельных HTTP-стресс-тестов через Nginx → PHP-FPM (`baseline` демонстрирует race condition, а `pessimistic`, `optimistic`, `mutex` и `atomic` строго удерживают инварианты I1–I5).
- `tests/Payment/Idempotency/RedisIdempotencyFilterTest.php`: Тесты атомарного захвата, конфликта, освобождения и 24h-фиксации ключей в реальном Redis.
- `tests/Service/`: `PaymentServiceTest`, `PaymentPostProcessingTest` (проверка Redis-фильтра, обработки ретраев `OptimisticLockStrategy` и диспатча `PaymentReceivedEvent` / `OrderFullyPaidEvent`).
- `tests/MessageHandler/` и `tests/Messenger/`: `SendPaymentReceiptHandlerTest`, `SendOrderPaidWebhookHandlerTest`, `MessengerFailureAndRetryTest`.
- `tests/Benchmark/` и `tests/Command/`: `ConsistencyAuditorTest`, `BenchmarkRunCommandTest`.

---

## 10. Заметки по техническим нюансам и потенциальным доработкам

В ходе анализа кодовой базы учтены следующие архитектурные особенности:

1. **Пост-обработка событий после retry в `OptimisticLockStrategy` (`PaymentService::pay`) — [ИСПРАВЛЕНО]:**
   - В [`PaymentService.php`](file:///\\wsl.localhost\Ubuntu\home\c959\projects\splitpay\src\Service\PaymentService.php#L79-L108) пост-обработка теперь использует `$resolvedOrder = $payment->getOrder() ?? $order` и `$resolvedUser = $payment->getUser() ?? $user`. Это гарантирует, что при перезапуске `EntityManager` внутри [`OptimisticLockStrategy`](file:///\\wsl.localhost\Ubuntu\home\c959\projects\splitpay\src\Payment\Strategy\OptimisticLockStrategy.php) событие [`OrderFullyPaidEvent`](file:///\\wsl.localhost\Ubuntu\home\c959\projects\splitpay\src\Message\OrderFullyPaidEvent.php) корректно диспатчится в RabbitMQ на основе актуального состояния заказа.
2. **Взаимодействие `RedisIdempotencyFilter` с бенчмарком:**
   - Так как `BenchmarkRunner` генерирует уникальный `idempotencyKey` на каждый запрос, Redis-префильтр пропускает все запросы в PostgreSQL, не искажая тестирование конкурентности БД. В реальном же сценарии дубликаты отсекаются на уровне Redis за миллисекунды.
3. **Разделение форматов сумм:**
   - REST API принимает суммы строго в целых центах (`fullPriceCents`, `amountCents`), тогда как веб-формы в `WebOrderController` и `WebBenchmarkController` принимают доллары с плавающей точкой (`full_price_dollars`, `amount_dollars`) и конвертируют их через `(int) round($dollars * 100)`.
4. **Безопасность веб-интерфейса:**
   - В проекте намеренно отсутствуют `SecurityBundle`, авторизация и CSRF-токены на веб-формах (экспериментальный стенд для локального запуска).
5. **PHPUnit 13 warnings:**
   - В старых тестах с заглушками без ожиданий (`createMock()` без `expects()`) PHPUnit 13 выводит 2 notice/deprecation; их можно заменить на `createStub()` для полностью чистого вывода.

---

## 11. Быстрые команды (для разработчика и агента)

```bash
# Поднять всю инфраструктуру (php, nginx, database, redis, rabbitmq)
docker compose up -d --build

# Применить миграции БД
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Запустить воркер обработки очередей RabbitMQ (чеки и вебхуки)
docker compose exec php php bin/console messenger:consume async -vv

# Проверить валидность контейнера, шаблонов Twig, YAML и схемы БД
docker compose exec php php bin/console lint:container
docker compose exec php php bin/console lint:twig templates/
docker compose exec php php bin/console lint:yaml config/
docker compose exec php php bin/console doctrine:schema:validate

# Запустить все 62 теста
docker compose exec php php bin/phpunit

# Запустить бенчмарк всех 5 стратегий из CLI
docker compose exec php php bin/console app:benchmark:run --strategy all --requests 50 --concurrency 10
```
