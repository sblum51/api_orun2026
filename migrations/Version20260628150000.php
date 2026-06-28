<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `courses.climb_m` for the total positive elevation gain (D+) of a
 * circuit. IOF XML imports already parse this from `<Climb>` — they just
 * never had a column to write to.
 */
final class Version20260628150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add courses.climb_m for elevation gain.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses ADD COLUMN climb_m INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses DROP COLUMN climb_m');
    }
}
