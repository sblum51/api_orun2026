<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `last_location` fields to `activities` for live tracking. The
 * mobile app pushes the runner's position every 30 s while the run is
 * active AND the runner has opted into live tracking; the manager
 * polls a rankings-like endpoint to render points on the event map.
 *
 * We store only the LATEST reading — historical traces belong on a
 * separate table if we ever want to draw route paths. Storing the
 * whole trail on activities would balloon the row size for zero
 * current UX benefit.
 */
final class Version20260705100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add activities.last_lat/lng/at for live tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities ADD COLUMN last_lat DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE activities ADD COLUMN last_lng DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE activities ADD COLUMN last_located_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        // Fast scan for "give me every runner of this event whose last
        // point is fresher than X". Only useful once we have real
        // volume; harmless before.
        $this->addSql('CREATE INDEX activities_last_located_at_idx ON activities (last_located_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX activities_last_located_at_idx');
        $this->addSql('ALTER TABLE activities DROP COLUMN last_located_at');
        $this->addSql('ALTER TABLE activities DROP COLUMN last_lng');
        $this->addSql('ALTER TABLE activities DROP COLUMN last_lat');
    }
}
