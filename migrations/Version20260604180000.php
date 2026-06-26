<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds dedicated Start (triangle) and Finish (double circle) positions to
 * Course. Both are nullable — courses without geolocated controls don't need
 * them, and existing rows pre-date the field.
 */
final class Version20260604180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add start/finish lat/lng columns to courses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses ADD start_latitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE courses ADD start_longitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE courses ADD finish_latitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE courses ADD finish_longitude DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses DROP start_latitude');
        $this->addSql('ALTER TABLE courses DROP start_longitude');
        $this->addSql('ALTER TABLE courses DROP finish_latitude');
        $this->addSql('ALTER TABLE courses DROP finish_longitude');
    }
}
