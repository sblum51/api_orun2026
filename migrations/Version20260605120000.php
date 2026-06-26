<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `tags` table — the operator's library of physical NFC / QR /
 * iBeacon items. A tag is either filed under an organization (shared across
 * members) or kept in the creator's personal library (organization = null).
 */
final class Version20260605120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tags table for the manager-side validation tag library.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tags (
                id UUID NOT NULL,
                organization_id UUID DEFAULT NULL,
                creator_id UUID NOT NULL,
                type VARCHAR(30) NOT NULL,
                name VARCHAR(200) NOT NULL,
                payload JSON NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_tags_organization_id ON tags (organization_id)');
        $this->addSql('CREATE INDEX IDX_tags_creator_id ON tags (creator_id)');
        $this->addSql('CREATE INDEX IDX_tags_type ON tags (type)');
        $this->addSql('ALTER TABLE tags ADD CONSTRAINT FK_tags_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tags ADD CONSTRAINT FK_tags_creator FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tags');
    }
}
