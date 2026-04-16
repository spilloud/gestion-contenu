<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add multi-role support for users and assign one editor (monteur) per client';
    }

    public function up(Schema $schema): void
    {
        // User: add JSON roles (keep legacy "role" column for backward compatibility)
        $this->addSql('ALTER TABLE "user" ADD roles JSON DEFAULT NULL');

        // Client: add editor (monteur) relation
        $this->addSql('ALTER TABLE client ADD editor_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_client_editor FOREIGN KEY (editor_id) REFERENCES "user" (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_client_editor_id ON client (editor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP CONSTRAINT FK_client_editor');
        $this->addSql('DROP INDEX IDX_client_editor_id');
        $this->addSql('ALTER TABLE client DROP COLUMN editor_id');

        $this->addSql('ALTER TABLE "user" DROP COLUMN roles');
    }
}

