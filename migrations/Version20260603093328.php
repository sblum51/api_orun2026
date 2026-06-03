<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260603093328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE organization_members (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_88725ABC32C8A3DE ON organization_members (organization_id)');
        $this->addSql('CREATE INDEX IDX_88725ABCA76ED395 ON organization_members (user_id)');
        $this->addSql('CREATE UNIQUE INDEX org_member_uniq ON organization_members (organization_id, user_id)');
        $this->addSql('ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABC32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABCA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE organizations DROP CONSTRAINT fk_427c1c7f7e3c61f9');
        $this->addSql('DROP INDEX idx_427c1c7f7e3c61f9');
        $this->addSql('ALTER TABLE organizations DROP owner_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization_members DROP CONSTRAINT FK_88725ABC32C8A3DE');
        $this->addSql('ALTER TABLE organization_members DROP CONSTRAINT FK_88725ABCA76ED395');
        $this->addSql('DROP TABLE organization_members');
        $this->addSql('ALTER TABLE organizations ADD owner_id UUID NOT NULL');
        $this->addSql('ALTER TABLE organizations ADD CONSTRAINT fk_427c1c7f7e3c61f9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_427c1c7f7e3c61f9 ON organizations (owner_id)');
    }
}
