<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mode Campagne : campaign, campaign_category, content.campaign_category_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE campaign (
            id SERIAL PRIMARY KEY,
            client_id INT NOT NULL REFERENCES client(id) ON DELETE CASCADE,
            name VARCHAR(255) NOT NULL,
            starts_on DATE NOT NULL,
            ends_on DATE NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE INDEX idx_campaign_client ON campaign (client_id)');
        $this->addSql('CREATE INDEX idx_campaign_period ON campaign (client_id, starts_on, ends_on)');

        $this->addSql('CREATE TABLE campaign_category (
            id SERIAL PRIMARY KEY,
            campaign_id INT NOT NULL REFERENCES campaign(id) ON DELETE CASCADE,
            name VARCHAR(255) NOT NULL,
            color VARCHAR(7) DEFAULT \'#e2e8f0\' NOT NULL,
            sort_order INT NOT NULL DEFAULT 0
        )');
        $this->addSql('CREATE INDEX idx_campaign_category_campaign ON campaign_category (campaign_id)');

        $this->addSql('ALTER TABLE content ADD campaign_category_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD CONSTRAINT fk_content_campaign_category FOREIGN KEY (campaign_category_id) REFERENCES campaign_category(id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_content_campaign_category ON content (campaign_category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content DROP CONSTRAINT fk_content_campaign_category');
        $this->addSql('DROP INDEX idx_content_campaign_category');
        $this->addSql('ALTER TABLE content DROP campaign_category_id');
        $this->addSql('DROP TABLE campaign_category');
        $this->addSql('DROP TABLE campaign');
    }
}
