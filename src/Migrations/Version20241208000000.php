<?php

declare(strict_types=1);

namespace PlanetRideSyliusAiAuditPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241208000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add AI audit fields to sylius_product_translation (score, content, updated_at).';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('sylius_product_translation');

        if (!$table->hasColumn('ai_audit_score')) {
            $this->addSql('ALTER TABLE sylius_product_translation ADD ai_audit_score INT DEFAULT NULL');
        }

        if (!$table->hasColumn('ai_audit_content')) {
            $this->addSql('ALTER TABLE sylius_product_translation ADD ai_audit_content LONGTEXT DEFAULT NULL');
        }

        if (!$table->hasColumn('ai_audit_updated_at')) {
            $this->addSql('ALTER TABLE sylius_product_translation ADD ai_audit_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('sylius_product_translation');

        if ($table->hasColumn('ai_audit_score')) {
            $this->addSql('ALTER TABLE sylius_product_translation DROP ai_audit_score');
        }

        if ($table->hasColumn('ai_audit_content')) {
            $this->addSql('ALTER TABLE sylius_product_translation DROP ai_audit_content');
        }

        if ($table->hasColumn('ai_audit_updated_at')) {
            $this->addSql('ALTER TABLE sylius_product_translation DROP ai_audit_updated_at');
        }
    }
}
