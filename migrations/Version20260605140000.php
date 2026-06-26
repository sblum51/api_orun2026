<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2 of the tag-library rollout: events store the validation methods
 * picked when they were created (used to prefill new controls) and controls
 * gain a many-to-many to Tag via the `control_tags` pivot.
 */
final class Version20260605140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add events.default_validation_methods and the control_tags pivot table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE events
            ADD COLUMN default_validation_methods JSON NOT NULL DEFAULT '[]'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE control_tags (
                control_id UUID NOT NULL,
                tag_id UUID NOT NULL,
                PRIMARY KEY(control_id, tag_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_control_tags_control_id ON control_tags (control_id)');
        $this->addSql('CREATE INDEX IDX_control_tags_tag_id ON control_tags (tag_id)');
        $this->addSql('ALTER TABLE control_tags ADD CONSTRAINT FK_control_tags_control FOREIGN KEY (control_id) REFERENCES controls (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE control_tags ADD CONSTRAINT FK_control_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE control_tags');
        $this->addSql('ALTER TABLE events DROP COLUMN default_validation_methods');
    }
}
