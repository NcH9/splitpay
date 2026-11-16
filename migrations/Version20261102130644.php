<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102130644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Section 1: Migrate to integer minor units, add version/status/idempotency_key, drop old float columns and legacy "user" table.';
    }

    public function up(Schema $schema): void
    {
        // ── users ────────────────────────────────────────────────────────────
        // Rename username → name
        $this->addSql('ALTER TABLE users RENAME COLUMN username TO name');
        // Add created_at (backfill with NOW() for existing rows)
        $this->addSql('ALTER TABLE users ADD COLUMN created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()');
        // Remove the temporary DEFAULT now that existing rows are populated
        $this->addSql('ALTER TABLE users ALTER COLUMN created_at DROP DEFAULT');

        // ── orders ───────────────────────────────────────────────────────────
        // Add new integer columns (DEFAULT 0 to satisfy NOT NULL for existing rows)
        $this->addSql('ALTER TABLE orders ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE orders ADD COLUMN full_price_cents INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE orders ADD COLUMN paid_price_cents INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE orders ADD COLUMN status VARCHAR(255) NOT NULL DEFAULT \'open\'');
        $this->addSql('ALTER TABLE orders ADD COLUMN version INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE orders ADD COLUMN created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()');
        $this->addSql('ALTER TABLE orders ADD COLUMN updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()');

        // Copy float values as cents (multiply by 100, round to nearest int)
        $this->addSql('UPDATE orders SET full_price_cents = ROUND(full_price * 100)::INT, paid_price_cents = ROUND(paid_price * 100)::INT');

        // Drop the old float columns and temporary defaults
        $this->addSql('ALTER TABLE orders DROP COLUMN full_price');
        $this->addSql('ALTER TABLE orders DROP COLUMN paid_price');
        $this->addSql('ALTER TABLE orders ALTER COLUMN name DROP DEFAULT');
        $this->addSql('ALTER TABLE orders ALTER COLUMN full_price_cents DROP DEFAULT');
        $this->addSql('ALTER TABLE orders ALTER COLUMN paid_price_cents DROP DEFAULT');
        $this->addSql('ALTER TABLE orders ALTER COLUMN status DROP DEFAULT');
        // Keep DEFAULT 1 on version column — Doctrine ORM 3 expects it for optimistic locking
        $this->addSql('ALTER TABLE orders ALTER COLUMN version SET DEFAULT 1');
        $this->addSql('ALTER TABLE orders ALTER COLUMN created_at DROP DEFAULT');
        $this->addSql('ALTER TABLE orders ALTER COLUMN updated_at DROP DEFAULT');

        // ── payments ─────────────────────────────────────────────────────────
        // Add new integer column
        $this->addSql('ALTER TABLE payments ADD COLUMN amount_cents INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE payments ADD COLUMN payment_method VARCHAR(255) NOT NULL DEFAULT \'card\'');
        $this->addSql('ALTER TABLE payments ADD COLUMN idempotency_key VARCHAR(255) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE payments ADD COLUMN created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()');

        // Copy float amount to cents
        $this->addSql('UPDATE payments SET amount_cents = ROUND(paid_amount * 100)::INT');

        // Generate unique idempotency keys for existing rows (use a UUID-like fallback)
        $this->addSql('UPDATE payments SET idempotency_key = CONCAT(\'legacy-\', id::TEXT)');

        // Drop old float column and temporary defaults
        $this->addSql('ALTER TABLE payments DROP COLUMN paid_amount');
        $this->addSql('ALTER TABLE payments ALTER COLUMN amount_cents DROP DEFAULT');
        $this->addSql('ALTER TABLE payments ALTER COLUMN payment_method DROP DEFAULT');
        $this->addSql('ALTER TABLE payments ALTER COLUMN created_at DROP DEFAULT');
        $this->addSql('ALTER TABLE payments ALTER COLUMN idempotency_key DROP DEFAULT');

        // Create unique index on idempotency_key
        $this->addSql('CREATE UNIQUE INDEX UNIQ_65D29B327FD1C147 ON payments (idempotency_key)');

        // Drop the leftover legacy "user" table (quoted, singular) if it exists
        $this->addSql('DROP TABLE IF EXISTS "user"');
    }

    public function down(Schema $schema): void
    {
        // ── payments ─────────────────────────────────────────────────────────
        $this->addSql('DROP INDEX UNIQ_65D29B327FD1C147');
        $this->addSql('ALTER TABLE payments ADD COLUMN paid_amount DOUBLE PRECISION NOT NULL DEFAULT 0');
        $this->addSql('UPDATE payments SET paid_amount = amount_cents::FLOAT / 100');
        $this->addSql('ALTER TABLE payments DROP COLUMN amount_cents');
        $this->addSql('ALTER TABLE payments DROP COLUMN payment_method');
        $this->addSql('ALTER TABLE payments DROP COLUMN idempotency_key');
        $this->addSql('ALTER TABLE payments DROP COLUMN created_at');
        $this->addSql('ALTER TABLE payments ALTER COLUMN paid_amount DROP DEFAULT');

        // ── orders ───────────────────────────────────────────────────────────
        $this->addSql('ALTER TABLE orders ADD COLUMN full_price DOUBLE PRECISION NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE orders ADD COLUMN paid_price DOUBLE PRECISION NOT NULL DEFAULT 0');
        $this->addSql('UPDATE orders SET full_price = full_price_cents::FLOAT / 100, paid_price = paid_price_cents::FLOAT / 100');
        $this->addSql('ALTER TABLE orders DROP COLUMN full_price_cents');
        $this->addSql('ALTER TABLE orders DROP COLUMN paid_price_cents');
        $this->addSql('ALTER TABLE orders DROP COLUMN name');
        $this->addSql('ALTER TABLE orders DROP COLUMN status');
        $this->addSql('ALTER TABLE orders DROP COLUMN version');
        $this->addSql('ALTER TABLE orders DROP COLUMN created_at');
        $this->addSql('ALTER TABLE orders DROP COLUMN updated_at');
        $this->addSql('ALTER TABLE orders ALTER COLUMN full_price DROP DEFAULT');
        $this->addSql('ALTER TABLE orders ALTER COLUMN paid_price DROP DEFAULT');

        // ── users ────────────────────────────────────────────────────────────
        $this->addSql('ALTER TABLE users RENAME COLUMN name TO username');
        $this->addSql('ALTER TABLE users DROP COLUMN created_at');
    }
}
