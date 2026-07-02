<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `activities.pseudo` for the display name used on live-ranking
 * boards. The mobile app collects it at the pre-start modal so runners
 * can appear under a chosen alias; null means anonymous and the manager
 * falls back to the linked user's account name.
 */
final class Version20260702110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add activities.pseudo for live-ranking display name.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities ADD COLUMN pseudo VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE activities ADD COLUMN local_run_id VARCHAR(36) DEFAULT NULL');
        // Unique per (user, localRunId) so the mobile can retry its
        // sync request without spawning duplicate activities. Enforced
        // as a partial index so multiple NULLs remain allowed (legacy
        // rows and manager-created activities).
        $this->addSql('CREATE UNIQUE INDEX activities_user_local_run_uniq ON activities (user_id, local_run_id) WHERE local_run_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX activities_user_local_run_uniq');
        $this->addSql('ALTER TABLE activities DROP COLUMN local_run_id');
        $this->addSql('ALTER TABLE activities DROP COLUMN pseudo');
    }
}
