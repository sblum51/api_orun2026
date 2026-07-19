<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the feedback + social-share modules to events.
 *
 *   * `events.feedback_enabled` (bool, default TRUE) — when off, the
 *     mobile hides the post-run rating dialog AND the manager's
 *     feedback view. Default TRUE so existing events opt-in
 *     automatically; managers who want silence flip it off.
 *   * `events.share_enabled` (bool, default TRUE) — same pattern for
 *     the social-share panel. Off = the "share" button hides on the
 *     event detail page.
 *   * `feedbacks` — one row per runner-per-activity feedback: 1-5
 *     rating + optional comment. Unique on (activity_id) so a runner
 *     can update / replace their feedback but not stuff the box.
 */
final class Version20260719100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feedback + share modules on events + feedbacks table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD COLUMN feedback_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE events ADD COLUMN share_enabled BOOLEAN NOT NULL DEFAULT TRUE');

        $this->addSql(<<<'SQL'
            CREATE TABLE feedbacks (
                id UUID NOT NULL,
                activity_id UUID NOT NULL,
                rating SMALLINT NOT NULL,
                comment TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT feedbacks_rating_range CHECK (rating BETWEEN 1 AND 5)
            )
        SQL);
        // One feedback per activity — runners can PATCH to update their
        // existing entry; no way to spam.
        $this->addSql('CREATE UNIQUE INDEX feedbacks_activity_uniq ON feedbacks (activity_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE feedbacks
                ADD CONSTRAINT fk_feedbacks_activity
                FOREIGN KEY (activity_id) REFERENCES activities (id)
                ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE feedbacks');
        $this->addSql('ALTER TABLE events DROP COLUMN share_enabled');
        $this->addSql('ALTER TABLE events DROP COLUMN feedback_enabled');
    }
}
