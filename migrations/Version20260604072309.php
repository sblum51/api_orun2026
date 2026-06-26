<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Standalone events: make events.organization_id nullable and add a NOT NULL
 * creator_id, backfilled from the organization's earliest member.
 */
final class Version20260604072309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make Event.organization nullable and add Event.creator (backfilled from organization members).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD creator_id UUID NULL');
        $this->addSql('ALTER TABLE events ALTER organization_id DROP NOT NULL');

        // Backfill creator_id with the organization's earliest member, if any.
        $this->addSql(<<<'SQL'
            UPDATE events e
            SET creator_id = (
                SELECT om.user_id
                FROM organization_members om
                WHERE om.organization_id = e.organization_id
                ORDER BY om.created_at ASC
                LIMIT 1
            )
            WHERE e.creator_id IS NULL AND e.organization_id IS NOT NULL
        SQL);

        $this->addSql('ALTER TABLE events ALTER creator_id SET NOT NULL');
        $this->addSql('ALTER TABLE events ADD CONSTRAINT FK_5387574A61220EA6 FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5387574A61220EA6 ON events (creator_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events DROP CONSTRAINT FK_5387574A61220EA6');
        $this->addSql('DROP INDEX IDX_5387574A61220EA6');
        $this->addSql('ALTER TABLE events DROP creator_id');
        $this->addSql('ALTER TABLE events ALTER organization_id SET NOT NULL');
    }
}
