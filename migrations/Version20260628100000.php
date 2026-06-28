<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Promotes Start/Finish to first-class Control types: each control row now
 * carries a `type` (default 'control'), an optional `label` (S1/F1 display)
 * and a nullable `code` (only set for type='control'). The
 * (event, code) uniqueness becomes a partial unique index — applies only
 * when `code` is not null, so several Start/Finish rows can coexist on
 * the same event without colliding.
 */
final class Version20260628100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add controls.type + controls.label, make code nullable, partial unique on (event, code).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE controls
            ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'control',
            ADD COLUMN label VARCHAR(50) DEFAULT NULL,
            ALTER COLUMN code DROP NOT NULL
        SQL);
        // The pre-existing UNIQUE (event_id, code) keeps working — Postgres
        // treats NULL as distinct in a unique constraint, so several
        // Start/Finish rows (code IS NULL) can coexist on the same event
        // while duplicate numeric codes on type='control' are still rejected.
    }

    public function down(Schema $schema): void
    {
        // Reverse: forbid null codes again. Will fail if any Start/Finish
        // rows exist — caller must clean them up first.
        $this->addSql('ALTER TABLE controls ALTER COLUMN code SET NOT NULL');
        $this->addSql('ALTER TABLE controls DROP COLUMN type, DROP COLUMN label');
    }
}
