<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508162600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add client archive flag (is_archived)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD is_archived BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('CREATE INDEX IDX_client_is_archived ON client (is_archived)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_client_is_archived');
        $this->addSql('ALTER TABLE client DROP is_archived');
    }
}

