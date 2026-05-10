<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI / Lucy API settings (token + allowed IPs) singleton row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_api_config (id INT NOT NULL, api_token VARCHAR(512) DEFAULT NULL, allowed_ips TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO ai_api_config (id, api_token, allowed_ips) VALUES (1, NULL, NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_api_config');
    }
}
