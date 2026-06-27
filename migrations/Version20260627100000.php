<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `courses.starts` and `courses.finishes` JSON arrays so a course can
 * declare several start/finish points (multi-loop, mass-start variants,
 * shadow finishes). Back-fills from the legacy scalar columns so existing
 * data keeps working — the columns remain in place for now and mirror
 * `starts[0]` / `finishes[0]` via the entity setters.
 */
final class Version20260627100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add courses.starts and courses.finishes JSON arrays for multi-S/F support.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE courses
            ADD COLUMN starts JSON NOT NULL DEFAULT '[]',
            ADD COLUMN finishes JSON NOT NULL DEFAULT '[]'
        SQL);

        // Backfill: wrap the legacy scalar columns in a single-element array
        // for every course that already had a Start / Finish coordinate.
        $this->addSql(<<<'SQL'
            UPDATE courses
            SET starts = json_build_array(json_build_object(
                'latitude', start_latitude,
                'longitude', start_longitude
            ))
            WHERE start_latitude IS NOT NULL AND start_longitude IS NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE courses
            SET finishes = json_build_array(json_build_object(
                'latitude', finish_latitude,
                'longitude', finish_longitude
            ))
            WHERE finish_latitude IS NOT NULL AND finish_longitude IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses DROP COLUMN starts, DROP COLUMN finishes');
    }
}
