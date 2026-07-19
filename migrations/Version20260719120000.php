<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `events.legacy_slug` so imports from the historical Orun API
 * (`https://api.orun.app/events/<slug>`) are idempotent: re-running an
 * import for the same legacy event upserts the existing row instead
 * of creating a duplicate. Unique partial index so events created
 * directly in the new backend keep NULL and don't collide.
 */
final class Version20260719120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add events.legacy_slug for idempotent legacy imports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD COLUMN legacy_slug VARCHAR(210) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX events_legacy_slug_uniq ON events (legacy_slug) WHERE legacy_slug IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX events_legacy_slug_uniq');
        $this->addSql('ALTER TABLE events DROP COLUMN legacy_slug');
    }
}
