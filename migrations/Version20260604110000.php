<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Switch Control.validation_method (single enum-string) → validation_methods
 * (JSON array of method values). A control can now expose several validation
 * methods at once (QR + NFC + GPS fallback on the same stake).
 */
final class Version20260604110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace controls.validation_method (string) with controls.validation_methods (json array).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE controls ADD validation_methods JSON DEFAULT NULL');
        // Backfill: wrap the single method value into a one-element JSON array.
        $this->addSql(<<<'SQL'
            UPDATE controls
            SET validation_methods = json_build_array(validation_method)
            WHERE validation_method IS NOT NULL
        SQL);
        // Anything left null gets an empty array so the NOT NULL constraint can land safely.
        $this->addSql("UPDATE controls SET validation_methods = '[]'::json WHERE validation_methods IS NULL");
        $this->addSql('ALTER TABLE controls ALTER validation_methods SET NOT NULL');
        $this->addSql('ALTER TABLE controls DROP validation_method');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE controls ADD validation_method VARCHAR(30) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE controls
            SET validation_method = validation_methods->>0
            WHERE jsonb_array_length(validation_methods::jsonb) > 0
        SQL);
        $this->addSql('ALTER TABLE controls ALTER validation_method SET NOT NULL');
        $this->addSql('ALTER TABLE controls DROP validation_methods');
    }
}
