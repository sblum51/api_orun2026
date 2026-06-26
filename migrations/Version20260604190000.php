<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Course.drawCircuitOverlay — toggles whether the manager + mobile app
 * paint IOF symbols (start triangle, control circles, finish double circle,
 * connecting line) on top of the basemap. False when the GroundOverlay
 * image already ships those graphics baked in (common for OCAD per-circuit
 * KMZ exports).
 */
final class Version20260604190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add draw_circuit_overlay column to courses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses ADD draw_circuit_overlay BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses DROP draw_circuit_overlay');
    }
}
