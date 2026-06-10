<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Échéance montage Asana synchronisée sur content.asana_montage_due_on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content ADD asana_montage_due_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content DROP asana_montage_due_on');
    }
}
