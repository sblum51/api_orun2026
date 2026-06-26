<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the optional cover photo used to illustrate an event in lists and
 * detail screens. Stored as a URL because uploads go through MapStorage
 * (Flysystem) which already returns public URLs — keeps things simple.
 */
final class Version20260606080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add events.cover_image_url for the illustration photo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD COLUMN cover_image_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events DROP COLUMN cover_image_url');
    }
}
