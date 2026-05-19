<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519165921 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activities (status VARCHAR(20) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, total_duration_sec INT DEFAULT NULL, total_score INT DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, course_id UUID NOT NULL, team_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B5F1AFE5A76ED395 ON activities (user_id)');
        $this->addSql('CREATE INDEX IDX_B5F1AFE5591CC992 ON activities (course_id)');
        $this->addSql('CREATE INDEX IDX_B5F1AFE5296CD8AE ON activities (team_id)');
        $this->addSql('CREATE TABLE controls (code SMALLINT NOT NULL, validation_method VARCHAR(30) NOT NULL, payload JSON NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, note VARCHAR(200) DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FAED8C3F71F7E88B ON controls (event_id)');
        $this->addSql('CREATE UNIQUE INDEX controls_event_code_uniq ON controls (event_id, code)');
        $this->addSql('CREATE TABLE course_controls (position INT NOT NULL, score INT DEFAULT NULL, pair_required BOOLEAN NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, course_id UUID NOT NULL, control_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5359E169591CC992 ON course_controls (course_id)');
        $this->addSql('CREATE INDEX IDX_5359E16932BEC70E ON course_controls (control_id)');
        $this->addSql('CREATE UNIQUE INDEX course_controls_course_position_uniq ON course_controls (course_id, position)');
        $this->addSql('CREATE TABLE courses (name VARCHAR(100) NOT NULL, type VARCHAR(30) NOT NULL, duration_limit_min INT DEFAULT NULL, distance_km NUMERIC(6, 2) DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A9A55A4C71F7E88B ON courses (event_id)');
        $this->addSql('CREATE TABLE events (name VARCHAR(200) NOT NULL, slug VARCHAR(210) NOT NULL, description TEXT DEFAULT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, location VARCHAR(200) DEFAULT NULL, published BOOLEAN NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5387574A989D9B62 ON events (slug)');
        $this->addSql('CREATE INDEX IDX_5387574A32C8A3DE ON events (organization_id)');
        $this->addSql('CREATE TABLE maps (name VARCHAR(100) NOT NULL, image_url VARCHAR(1000) NOT NULL, bounds JSON DEFAULT NULL, scale INT DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, course_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_472E08A5591CC992 ON maps (course_id)');
        $this->addSql('CREATE TABLE organizations (name VARCHAR(150) NOT NULL, slug VARCHAR(160) NOT NULL, description TEXT DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_427C1C7F989D9B62 ON organizations (slug)');
        $this->addSql('CREATE INDEX IDX_427C1C7F7E3C61F9 ON organizations (owner_id)');
        $this->addSql('CREATE TABLE password_reset_tokens (token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3967A216B3BC57DA ON password_reset_tokens (token_hash)');
        $this->addSql('CREATE INDEX IDX_3967A216A76ED395 ON password_reset_tokens (user_id)');
        $this->addSql('CREATE TABLE punches (punched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, method_used VARCHAR(30) NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, id UUID NOT NULL, activity_id UUID NOT NULL, control_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B238271A81C06096 ON punches (activity_id)');
        $this->addSql('CREATE INDEX IDX_B238271A32BEC70E ON punches (control_id)');
        $this->addSql('CREATE TABLE teams (name VARCHAR(100) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, course_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_96C22258591CC992 ON teams (course_id)');
        $this->addSql('CREATE TABLE users (email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('ALTER TABLE activities ADD CONSTRAINT FK_B5F1AFE5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activities ADD CONSTRAINT FK_B5F1AFE5591CC992 FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activities ADD CONSTRAINT FK_B5F1AFE5296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE controls ADD CONSTRAINT FK_FAED8C3F71F7E88B FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE course_controls ADD CONSTRAINT FK_5359E169591CC992 FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE course_controls ADD CONSTRAINT FK_5359E16932BEC70E FOREIGN KEY (control_id) REFERENCES controls (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE courses ADD CONSTRAINT FK_A9A55A4C71F7E88B FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE events ADD CONSTRAINT FK_5387574A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE maps ADD CONSTRAINT FK_472E08A5591CC992 FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE organizations ADD CONSTRAINT FK_427C1C7F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE password_reset_tokens ADD CONSTRAINT FK_3967A216A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE punches ADD CONSTRAINT FK_B238271A81C06096 FOREIGN KEY (activity_id) REFERENCES activities (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE punches ADD CONSTRAINT FK_B238271A32BEC70E FOREIGN KEY (control_id) REFERENCES controls (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE teams ADD CONSTRAINT FK_96C22258591CC992 FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activities DROP CONSTRAINT FK_B5F1AFE5A76ED395');
        $this->addSql('ALTER TABLE activities DROP CONSTRAINT FK_B5F1AFE5591CC992');
        $this->addSql('ALTER TABLE activities DROP CONSTRAINT FK_B5F1AFE5296CD8AE');
        $this->addSql('ALTER TABLE controls DROP CONSTRAINT FK_FAED8C3F71F7E88B');
        $this->addSql('ALTER TABLE course_controls DROP CONSTRAINT FK_5359E169591CC992');
        $this->addSql('ALTER TABLE course_controls DROP CONSTRAINT FK_5359E16932BEC70E');
        $this->addSql('ALTER TABLE courses DROP CONSTRAINT FK_A9A55A4C71F7E88B');
        $this->addSql('ALTER TABLE events DROP CONSTRAINT FK_5387574A32C8A3DE');
        $this->addSql('ALTER TABLE maps DROP CONSTRAINT FK_472E08A5591CC992');
        $this->addSql('ALTER TABLE organizations DROP CONSTRAINT FK_427C1C7F7E3C61F9');
        $this->addSql('ALTER TABLE password_reset_tokens DROP CONSTRAINT FK_3967A216A76ED395');
        $this->addSql('ALTER TABLE punches DROP CONSTRAINT FK_B238271A81C06096');
        $this->addSql('ALTER TABLE punches DROP CONSTRAINT FK_B238271A32BEC70E');
        $this->addSql('ALTER TABLE teams DROP CONSTRAINT FK_96C22258591CC992');
        $this->addSql('DROP TABLE activities');
        $this->addSql('DROP TABLE controls');
        $this->addSql('DROP TABLE course_controls');
        $this->addSql('DROP TABLE courses');
        $this->addSql('DROP TABLE events');
        $this->addSql('DROP TABLE maps');
        $this->addSql('DROP TABLE organizations');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP TABLE punches');
        $this->addSql('DROP TABLE teams');
        $this->addSql('DROP TABLE users');
    }
}
