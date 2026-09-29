```markdown
# SplitPay — Анализ проекта (материал для контекста агента)

> Назначение документа: дать агенту-разработчику полную и точную картину текущего состояния
> проекта SplitPay — стек, структуру, доменную модель, конфигурацию, инфраструктуру и текущий
> уровень реализации — чтобы он мог продолжить работу без повторного исследования кодовой базы.

---

## 1. Общее резюме

**SplitPay** — это скелет Symfony-приложения (Symfony 8.1, PHP не ниже 8.4) с подключённым Doctrine
ORM и PostgreSQL. На текущий момент это **каркас предметной области, а не работающий продукт**:

- Есть 3 entity: `User`, `Order`, `Payment` с корректными двунаправленными связями.
- Есть 2 контроллера (`UserController`, `OrderController`), которые являются **сгенерированными
  заглушками** (`make:controller`) и возвращают статический JSON.
- Репозитории — **пустые заготовки** `ServiceEntityRepository` без кастомных методов.
- Есть 2 миграции БД, создающие таблицы.
- Есть 2 тривиальных HTTP-теста, проверяющих только 200-ответ заглушек.
- **Бизнес-логика (сервисы, распределение платежей, расчёт долгов) отсутствует полностью.**

По названию и модели данных проект предназначен для **совместной оплаты заказов** («split pay»):
пользователи делают взносы (`Payment`) в общий заказ (`Order`), а система, предположительно,
считает, кто сколько внёс и кто кому должен доплатить. Эта логика пока не реализована.

---

## 2. Стек и версии

Из `composer.json`:

| Компонент | Версия |
|---|---|
| PHP | не ниже 8.4 |
| Symfony (framework-bundle, console, dotenv, runtime, yaml) | 8.1.* |
| doctrine/doctrine-bundle | ^3.3 |
| doctrine/doctrine-migrations-bundle | ^4.0 |
| doctrine/orm | ^3.7 |
| symfony/flex | ^2 |
| symfony/maker-bundle (dev) | ^1.68 |

Фактически установлено (`symfony.lock`): doctrine-bundle 3.3, migrations-bundle 4.0,
console/framework-bundle/routing 8.1, flex 2.11, maker-bundle 1.68.

**Чего НЕ установлено (важно):**
- Twig / шаблоны — нет (server-rendered интерфейса нет).
- API Platform — нет.
- Symfony Serializer — нет (контроллеры используют только `AbstractController::json()`).
- Symfony Validator, Form — нет.
- SecurityBundle — нет (аутентификации/авторизации нет).
- Messenger, Lock, Mailer, HttpClient, Scheduler — нет.

`extra.symfony.allow-contrib: false` и `require: 8.1.*` — Flex ограничен официальными рецептами.

---

## 3. Структура проекта
```

splitpay/ |-- AGENTS.md # инструкции проекта (Symfony best practices) |-- CLAUDE.md # @AGENTS.md |-- composer.json / composer.lock |-- symfony.lock |-- Dockerfile # php:8.4-fpm + pdo_pgsql + composer |-- compose.yaml # php-fpm, nginx (:8080), postgres:16 |-- compose.override.yaml # переопределение портов БД |-- .env # дефолты (коммитится) |-- bin/console |-- config/ | |-- bundles.php | |-- preload.php | |-- reference.php # авто-сгенерированная схема конфигов | |-- routes.yaml # импорт routing.controllers | |-- routes/framework.yaml # /_error во dev | |-- services.yaml # autowire/autoconfigure для App | |-- packages/ | |-- cache.yaml | |-- doctrine.yaml | |-- doctrine_migrations.yaml | |-- framework.yaml | |-- routing.yaml |-- docker/nginx/default.conf # root=/var/www/html/public, fastcgi php:9000 |-- migrations/ | |-- Version20260918162837.php # создаёт таблицу "user" | |-- Version20260920113751.php # создаёт "order", payment + FK |-- public/index.php # front controller (runtime) |-- src/ | |-- Kernel.php # MicroKernelTrait, env: prod|dev|test | |-- Controller/ | | |-- OrderController.php # GET /order - JSON-заглушка | | |-- UserController.php # GET /user - JSON-заглушка | |-- Entity/ | | |-- Order.php | | |-- Payment.php | | |-- User.php | |-- Repository/ | |-- OrderRepository.php | |-- PaymentRepository.php | |-- UserRepository.php |-- tests/Controller/ |-- OrderControllerTest.php # GET /order - 200 |-- UserControllerTest.php # GET /user - 200

```javascript

---

## 4. Доменная модель (Doctrine ORM, атрибуты)

Связи (по факту — одна «звезда» вокруг `User`):
```

User 1 --(has many)--> Order 1 --(has many)--> Payment Payment --(belongs to)--> User (кто платил) Payment --(belongs to)--> Order (в какой заказ)

````javascript

- `User.orders`   — OneToMany (mappedBy `Order.user`)
- `User.payments` — OneToMany (mappedBy `Payment.user`)
- `Order.user`    — ManyToOne (JoinColumn NOT NULL, inversedBy `orders`)
- `Order.payments`— OneToMany (mappedBy `Payment.order`)
- `Payment.user`  — ManyToOne (JoinColumn NOT NULL, inversedBy `payments`)
- `Payment.order` — ManyToOne (JoinColumn NOT NULL, inversedBy `payments`)

### User (`src/Entity/User.php`)

| Поле | Тип | Примечание |
|---|---|---|
| `id` | `?int` | PK, auto (identity) |
| `username` | `?string` | length 255, NOT NULL |
| `orders` | Collection of Order | OneToMany |
| `payments` | Collection of Payment | OneToMany |

Проброс связей: `addOrder()` / `removeOrder()`, `addPayment()` / `removePayment()` — с корректной
синхронизацией обратной стороны и обнулением владельца (`setUser(null)`).

### Order (`src/Entity/Order.php`)

Таблица экранирована: `#[ORM\Table(name: 'orders')]` (order — зарезервированное слово SQL).

| Поле | Тип | Примечание |
|---|---|---|
| `id` | `?int` | PK, auto |
| `full_price` | `?float` | полная стоимость заказа, NOT NULL, DOUBLE |
| `paid_price` | `?float` | уже оплаченная сумма, NOT NULL, DOUBLE |
| `user` | `?User` | ManyToOne, NOT NULL (владелец/инициатор заказа) |
| `payments` | Collection of Payment | OneToMany |

Проброс: `addPayment()` (устанавливает `payment.order`), `removePayment()`.

### Payment (`src/Entity/Payment.php`)

Таблица: `payments`.

| Поле | Тип | Примечание |
|---|---|---|
| `id` | `?int` | PK, auto |
| `paid_amount` | `?float` | сумма взноса, NOT NULL, DOUBLE |
| `user` | `?User` | ManyToOne, NOT NULL (кто заплатил) |
| `order` | `?Order` | ManyToOne, NOT NULL (в какой заказ) |

**Наблюдения по модели:**
- Entity — **анемичные** (только геттеры/сеттеры, без доменных инвариантов).
- Нет timestamps (`createdAt`/`updatedAt`), нет статусов, нет валюты.
- Нет валидационных атрибутов (`#[Assert...]`) — Validator вообще не установлен.
- `paid_price` в `Order` дублирует агрегат по `Payment.paid_amount` — при реализации логики
  нужно решить, поддерживать ли его вручную или вычислять (риск рассинхронизации).
- Нет проверок вида `paid_price` не больше `full_price`, сумм больше нуля и т.п.

---

## 5. Слой HTTP (контроллеры)

Оба контроллера — final, наследуют `AbstractController`, маршруты через атрибут `#[Route]`.
Это **дефолтный вывод `make:controller`**, а не реальная логика:

```php
// OrderController
#[Route('/order', name: 'app_order')]
public function index(): JsonResponse
{
    return $this->json([
        'message' => 'Welcome to your new controller!',
        'path' => 'src/Controller/OrderController.php',
    ]);
}
````

`UserController` идентичен по структуре: `GET /user`, имя `app_user`.

Маршруты (полный список на текущий момент):

| Метод | Путь | Имя | Контроллер | |---|---|---|---| | GET | `/user` | `app_user` | `UserController::index` | | GET | `/order` | `app_order` | `OrderController::index` | | (dev) | `/_error*` | — | FrameworkBundle errors |

Импорт маршрутов: `config/routes.yaml` — resource `routing.controllers` (все `#[Route]` в `src/Controller` подхватываются автоматически). YAML/XML-роутинга нет — только атрибуты.

---

## 6. Слой доступа к данным (репозитории)

Все три репозитория — пустые `ServiceEntityRepository`-заготовки, сгенерированные Doctrine:

```php
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }
    // ...закомментированные примеры findByExampleField / findOneBySomeField
}
```

Кастомных запросов (QueryBuilder/DQL) __нет__. `PaymentRepository` и `UserRepository` — аналогично.

---

## 7. База данных и миграции

- СУБД: __PostgreSQL 16__ (`DATABASE_URL="postgresql://splitpay:secret@database:5432/splitpay?serverVersion=16&charset=utf8"`).
- Doctrine naming strategy: `underscore`; для PostgreSQL identity generation предпочтён `identity`.
- В `when@test` включён суффикс БД `_test` (`dbname_suffix: '_test%env(default::TEST_TOKEN)%'`).
- В `when@prod` настроены пулы кеша запросов/результатов.

Миграции:

1. `Version20260918162837` — `CREATE TABLE "user" (id IDENTITY, username VARCHAR(255))`.
2. `Version20260920113751` — `CREATE TABLE "order" (... user_id FK)`, `CREATE TABLE payment (... user_id, order_id FK)` + индексы и внешние ключи.

> ВНИМАНИЕ — __рассинхронизация имён таблиц:__ миграции создают таблицы `"user"` и `"order"` (единственное число), тогда как entity `User` и `Order` маппятся на `users` и `orders` (множественное число; `Order` переопределён через `#[ORM\Table]`). __Имена таблиц в миграциях не соответствуют маппингу entity__ — это потенциальный баг, который нужно проверить через `doctrine:schema:validate` и согласовать (исправить миграцию или маппинг).

---

## 8. Конфигурация приложения

- `config/bundles.php`: FrameworkBundle, DoctrineBundle, DoctrineMigrationsBundle — all; MakerBundle — dev.
- `config/services.yaml`: `_defaults` с `autowire: true`, `autoconfigure: true`; автоподключение сервисов из `src/` (`App` — `../src/`). Кастомных сервисов/биндингов нет.
- `config/packages/framework.yaml`: `secret: %env(APP_SECRET)%`, `session: true`; в `when@test` — `test: true` + mock-file storage.
- `config/packages/cache.yaml`: пулы не настроены (дефолт — filesystem).
- `config/packages/routing.yaml`: `default_uri: %env(DEFAULT_URI)%`.
- `Kernel.php`: `MicroKernelTrait`, допустимые окружения `prod|dev|test`.
- `.env` (коммитится, только дефолты): `APP_ENV=dev`, `DEFAULT_URI=http://localhost`, креды БД для докера. (`APP_SECRET` пуст — для dev нормально.)

---

## 9. Инфраструктура / Docker

- __`Dockerfile`__: `php:8.4-fpm`, ставит `git`, `unzip`, `libpq-dev`, `pdo_pgsql`, копирует composer из `composer:2`, `WORKDIR /var/www/html`, `CMD php-fpm`.

- __`compose.yaml`__:

  - `php` — сборка из Dockerfile, volume `./:/var/www/html`, сеть `symfony`.
  - `nginx` — `nginx:alpine`, порт __8080 к 80__, конфиг из `docker/nginx/default.conf`, зависит от php.
  - `database` — `postgres:16-alpine`, env из `.env`, порт 5432, volume `database_data`, healthcheck.

- __`compose.override.yaml`__ — убирает публикацию порта БД.

- __`docker/nginx/default.conf`__: root `/var/www/html/public`, `try_files ... /index.php`, `fastcgi_pass php:9000`, прямые `.php` (кроме index) возвращают 404.

Точка входа: `public/index.php` через `autoload_runtime.php` и `App\Kernel`.

---

## 10. Тесты

Symfony TestPack __не установлен__ явно, но `tests/` есть (тесты наследуют `WebTestCase`, значит framework-bundle + test-конфиг доступны). Тесты — тривиальные HTTP-проверки заглушек:

- `OrderControllerTest::testIndex()` — GET `/order`, `assertResponseIsSuccessful()`.
- `UserControllerTest::testIndex()` — GET `/user`, `assertResponseIsSuccessful()`.

Тестов на entity/сервисы/БД нет. Запуск: `php bin/phpunit` (или `vendor/bin/phpunit`).

---

## 11. Правила разработки проекта (`AGENTS.md`)

Ключевое (агент обязан соблюдать):

- __Symfony Best Practices__; PHP-атрибуты для метаданных (`#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]`, `#[Assert...]`, `#[AsCommand]`, `#[AsMessageHandler]` и т.д.). Никакого YAML/XML-роутинга.
- __Autowiring/autoconfiguration__; YAML-определения сервисов — последнее средство.
- Контроллеры тонкие, делегируют в сервисы, наследуют `AbstractController`.
- __Flex вместо ручной настройки__: новые возможности — через `composer require` (рецепт сам зарегистрирует бандл/конфиг). Не редактировать `config/bundles.php` вручную.
- __MapRequestPayload/MapQueryString__ для привязки данных запроса (вместо `json_decode`), при необходимости — `composer require` Serializer/Validator.
- __Constructor property promotion__, `readonly` для DTO/VO (но не помечать `readonly` сервис, который может стать `lazy`).
- __`symfony/lock`__ (`LockFactory`) для взаимного исключения — не самодельные флаги/лок-файлы.
- Doctrine: изменения схемы — __только через миграции__ (`make:migration` — `doctrine:migrations:migrate`), никогда `doctrine:schema:update` и не ручной SQL.
- Запуск: `symfony serve -d`, `symfony console ...` (или `bin/console`). Логи — `var/log/dev.log`, профайлер `/_profiler`.
- Секреты — в `.env.local` (git-ignored) или vault; `.env` содержит только дефолты.
- Тесты обязательны: feature не готов, пока нет теста, дергающего её как вызывающий код (HTTP-запрос для контроллера, вызов сервиса для сервиса).
- Стиль: `@Symfony` php-cs-fixer (если установлен `friendsofphp/php-cs-fixer`).
- __«Discover, don't guess»__: сверяться с установленной версией через `bin/console about`, `debug:router`, `debug:container`, `debug:autowiring`, `debug:config`, `lint:container`, исходники в `vendor/`.

---

## 12. Текущее состояние против предполагаемой бизнес-логики

__Что уже есть (каркас):__

- Модель данных `User`/`Order`/`Payment` со связями и каскадной синхронизацией коллекций.
- Схема БД через миграции.
- Инфраструктура запуска (docker: php-fpm + nginx + postgres).
- Тривиальные маршруты и тесты-заглушки.

__Чего ещё нет (кандидаты на реализацию):__

1. __Доменные сервисы__: учёт взносов в заказ, расчёт «кто сколько должен / кто кому должен», пересчёт `Order.paid_price` (или отказ от него в пользу вычисления из `payments`).
2. __REST API__: CRUD для `User`/`Order`, создание `Payment`, эндпоинты отчётов по заказу. (Потребуется `composer require symfony/serializer` + `symfony/validator`, DI-атрибуты `#[MapRequestPayload]`.)
3. __DTO + валидация__ (`#[Assert...]`, `readonly` DTO).
4. __Персистентность через сервисы__: сейчас контроллеры вообще не работают с EntityManager.
5. __Аутентификация/авторизация__ (SecurityBundle + аутентификатор + voters) — если нужно ограничивать доступ к заказам их участникам.
6. __Конкурентность__: параллельные взносы в один заказ — использовать `symfony/lock`.
7. __Тесты__: функциональные (WebTestCase с БД), юнит-тесты сервисов/расчётов, fixtures.
8. __Исправление несоответствия__ имён таблиц `User`/`Order`: entity (`users`, `orders`) против миграций (`user`, `order`) — проверить `doctrine:schema:validate` и согласовать.

---

## 13. Быстрые команды (для агента)

```bash
# поднять окружение
docker compose up -d            # php-fpm, nginx(:8080), postgres

# инфо о проекте
bin/console about
bin/console debug:router
bin/console debug:container --show-arguments
bin/console lint:container

# проверка соответствия схемы entity
bin/console doctrine:schema:validate

# миграции
bin/console make:migration
bin/console doctrine:migrations:migrate

# тесты
php bin/phpunit
```

### Краткие заметки/риски

- __Имена таблиц__: entity `User` — `users`, `Order` — `orders` (экранировано), а миграции создают `user` и `order`. Вероятное расхождение — проверить/исправить.
- `Order` — зарезервированное слово SQL, поэтому экранирование имени таблицы обязательно.
- `paid_price` — денормализация; нужно определить источник истины (поле или агрегат по `payments`).
- Тестовое окружение использует отдельную БД `_test` — для функциональных тестов потребуется настроить миграции/фикстуры для теста.