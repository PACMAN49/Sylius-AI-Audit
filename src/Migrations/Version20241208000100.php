<?php

declare(strict_types=1);

namespace PlanetRideSyliusAiAuditPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241208000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ai_audit_settings table for editable prompts.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('ai_audit_settings')) {
            return;
        }

        $this->addSql('CREATE TABLE ai_audit_settings (id INT NOT NULL, system_prompt LONGTEXT DEFAULT NULL, user_prompt LONGTEXT DEFAULT NULL, updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET UTF8mb4 COLLATE `UTF8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('ai_audit_settings')) {
            $this->addSql('DROP TABLE ai_audit_settings');
        }
    }
}
