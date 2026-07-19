<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Poste absent / abîmé" signalements — a runner marks a control as
 * broken and skips to the next one. Managers get a queue of pending
 * reports to act on.
 *
 * `control_reports` — one row per signalement:
 *   * activity + control (which run, which stake)
 *   * reason (missing / damaged)
 *   * comment (optional free text)
 *   * photo_url (optional S3/local URL of the uploaded photo)
 *   * status (pending / acknowledged / resolved / dismissed)
 *
 * `events.control_reports_enabled` (bool, default TRUE) — module
 * on/off per event, mirrors feedback/share flags added in the
 * previous migration.
 */
final class Version20260719110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add control_reports + events.control_reports_enabled toggle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE events ADD COLUMN control_reports_enabled BOOLEAN NOT NULL DEFAULT TRUE'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE control_reports (
                id UUID NOT NULL,
                activity_id UUID NOT NULL,
                control_id UUID NOT NULL,
                reason VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                comment TEXT DEFAULT NULL,
                photo_url VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                acknowledged_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                acknowledged_by_user_id UUID DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        // Fast filter for "give me every pending report on this event".
        $this->addSql('CREATE INDEX control_reports_status_idx ON control_reports (status)');
        $this->addSql('CREATE INDEX control_reports_control_idx ON control_reports (control_id)');
        $this->addSql('CREATE INDEX control_reports_activity_idx ON control_reports (activity_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE control_reports
                ADD CONSTRAINT fk_control_reports_activity
                FOREIGN KEY (activity_id) REFERENCES activities (id)
                ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE control_reports
                ADD CONSTRAINT fk_control_reports_control
                FOREIGN KEY (control_id) REFERENCES controls (id)
                ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE control_reports
                ADD CONSTRAINT fk_control_reports_ack_user
                FOREIGN KEY (acknowledged_by_user_id) REFERENCES users (id)
                ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE control_reports');
        $this->addSql('ALTER TABLE events DROP COLUMN control_reports_enabled');
    }
}
