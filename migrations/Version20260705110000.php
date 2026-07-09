<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 2 — emergency locate:
 *   - `user_devices` stores each user's iOS + Android push tokens so
 *     the server can wake the app with a silent push.
 *   - `location_requests` audits every "locate this runner" action so
 *     the runner can review who pinged them, when, and why. Required
 *     for the transparency clause of the CGU.
 */
final class Version20260705110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_devices + location_requests for emergency locate.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_devices (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                platform VARCHAR(10) NOT NULL,
                push_token VARCHAR(500) NOT NULL,
                app_version VARCHAR(50) DEFAULT NULL,
                last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX user_devices_user_platform_uniq ON user_devices (user_id, platform)'
        );
        $this->addSql(
            'CREATE INDEX user_devices_user_id_idx ON user_devices (user_id)'
        );
        $this->addSql(<<<'SQL'
            ALTER TABLE user_devices
                ADD CONSTRAINT fk_user_devices_user
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE location_requests (
                id UUID NOT NULL,
                activity_id UUID NOT NULL,
                requested_by_user_id UUID NOT NULL,
                reason VARCHAR(20) NOT NULL,
                free_text VARCHAR(255) DEFAULT NULL,
                requested_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                answered_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql(
            'CREATE INDEX location_requests_activity_id_idx ON location_requests (activity_id)'
        );
        $this->addSql(
            'CREATE INDEX location_requests_requested_at_idx ON location_requests (requested_at)'
        );
        $this->addSql(<<<'SQL'
            ALTER TABLE location_requests
                ADD CONSTRAINT fk_location_requests_activity
                FOREIGN KEY (activity_id) REFERENCES activities (id)
                ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE location_requests
                ADD CONSTRAINT fk_location_requests_user
                FOREIGN KEY (requested_by_user_id) REFERENCES users (id)
                ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE location_requests');
        $this->addSql('DROP TABLE user_devices');
    }
}
