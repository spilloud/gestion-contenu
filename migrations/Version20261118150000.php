<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configuration de publication par client (client.publishing_settings, JSON)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD publishing_settings JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP publishing_settings');
    }
}
