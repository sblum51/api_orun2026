<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unifies `controls.code` and `controls.label` into a single textual code.
 *
 * Before: `code` was a nullable smallint (31-400) for type='control'; Start/
 * Finish carried their display name in a separate `label` column.
 * After: `code` is `VARCHAR(10) NOT NULL` for every row — "31".."400" for
 * numbered stations, "S1"/"F1"/etc. for Start/Finish. `label` is dropped.
 *
 * The migration converts in two passes: copy existing scalar codes to text,
 * then fold the legacy `label` into `code` for any Start/Finish row.
 */
final class Version20260628170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'controls: merge label into code (VARCHAR(10) NOT NULL).';
    }

    public function up(Schema $schema): void
    {
        // 1. Allow text codes — switch column type, keep nullable for the
        //    duration of the data migration. NULL or empty stays invalid
        //    in the final NOT NULL constraint applied at the end.
        $this->addSql('ALTER TABLE controls ALTER COLUMN code TYPE VARCHAR(10) USING code::TEXT');

        // 2. Backfill Start/Finish rows: their textual id lived in `label`
        //    until now; merge it into `code` so every row has a value.
        $this->addSql(<<<'SQL'
            UPDATE controls
            SET code = label
            WHERE code IS NULL AND label IS NOT NULL AND label <> ''
        SQL);

        // 3. Last-resort placeholder for rows that have neither (shouldn't
        //    happen in real data — Start/Finish were created via the
        //    manager which always required a label — but be defensive
        //    so the NOT NULL doesn't blow up the migration).
        $this->addSql(<<<'SQL'
            UPDATE controls
            SET code = 'S?'
            WHERE code IS NULL AND type = 'start'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE controls
            SET code = 'F?'
            WHERE code IS NULL AND type = 'finish'
        SQL);

        // 4. Pin NOT NULL and drop the legacy label column.
        $this->addSql('ALTER TABLE controls ALTER COLUMN code SET NOT NULL');
        $this->addSql('ALTER TABLE controls DROP COLUMN label');
    }

    public function down(Schema $schema): void
    {
        // Rough reverse: revive `label`, parse digits back to smallint.
        // Anything that won't parse becomes NULL on the `code` column
        // and lives on in `label`.
        $this->addSql('ALTER TABLE controls ADD COLUMN label VARCHAR(50) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE controls
            SET label = code
            WHERE type <> 'control'
        SQL);
        $this->addSql('ALTER TABLE controls ALTER COLUMN code DROP NOT NULL');
        $this->addSql('ALTER TABLE controls ALTER COLUMN code TYPE SMALLINT USING NULLIF(regexp_replace(code, \'\\D\', \'\', \'g\'), \'\')::SMALLINT');
    }
}
