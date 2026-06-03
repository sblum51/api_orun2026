<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260603093518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE courses ADD visibility VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE events ADD type VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE events ADD visibility VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE events ALTER start_date DROP NOT NULL');
        $this->addSql('ALTER TABLE events ALTER end_date DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE courses DROP visibility');
        $this->addSql('ALTER TABLE events DROP type');
        $this->addSql('ALTER TABLE events DROP visibility');
        $this->addSql('ALTER TABLE events ALTER start_date SET NOT NULL');
        $this->addSql('ALTER TABLE events ALTER end_date SET NOT NULL');
    }
}
