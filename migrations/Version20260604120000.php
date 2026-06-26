<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the legacy "uwb" entry from controls.validation_methods arrays.
 * UWB validation isn't supported by the mobile app and the enum no longer
 * accepts it — any leftover value would now fail validation on the next
 * PATCH.
 */
final class Version20260604120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop "uwb" from controls.validation_methods arrays.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE controls
            SET validation_methods = COALESCE(
                (SELECT jsonb_agg(elem)
                 FROM jsonb_array_elements(validation_methods::jsonb) AS elem
                 WHERE elem::text <> '"uwb"'),
                '[]'::jsonb
            )::json
            WHERE validation_methods::jsonb @> '["uwb"]'::jsonb
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No-op: we don't reintroduce uwb.
    }
}
