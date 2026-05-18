<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add calendar_event table for multi-day calendar markers (global or per-client)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_event (
            id SERIAL NOT NULL,
            client_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            color VARCHAR(7) DEFAULT \'#cbd5e1\' NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_calendar_event_client ON calendar_event (client_id)');
        $this->addSql('CREATE INDEX IDX_calendar_event_dates ON calendar_event (start_date, end_date)');
        $this->addSql('ALTER TABLE calendar_event ADD CONSTRAINT FK_calendar_event_client FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_event DROP CONSTRAINT FK_calendar_event_client');
        $this->addSql('DROP TABLE calendar_event');
    }
}
