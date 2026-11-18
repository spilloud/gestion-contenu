<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Zone Metricool : heure de publication (content.scheduled_time) et validation CM (content.metricool_approved_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content ADD scheduled_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD metricool_approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content DROP scheduled_time');
        $this->addSql('ALTER TABLE content DROP metricool_approved_at');
    }
}
