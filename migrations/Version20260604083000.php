<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Course.controlsGeolocated — when true (default), the course's controls
 * are expected to carry latitude/longitude; when false, they're identified by
 * code only.
 */
final class Version20260604083000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add controls_geolocated to courses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses ADD controls_geolocated BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses DROP controls_geolocated');
    }
}
