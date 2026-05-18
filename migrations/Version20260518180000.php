<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add text_color column to calendar_event';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE calendar_event ADD text_color VARCHAR(7) DEFAULT '#0f172a' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_event DROP text_color');
    }
}
