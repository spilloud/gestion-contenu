<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demandes de tournage (shooting_request) et liaison aux vidéos planifiées';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shooting_request (
            id SERIAL PRIMARY KEY,
            client_id INT NOT NULL REFERENCES client(id) ON DELETE CASCADE,
            shooting_date DATE NOT NULL,
            description TEXT DEFAULT NULL,
            location VARCHAR(500) DEFAULT NULL,
            assigned_to_id INT NOT NULL REFERENCES "user"(id) ON DELETE RESTRICT,
            created_by_id INT DEFAULT NULL REFERENCES "user"(id) ON DELETE SET NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            asana_task_gid VARCHAR(64) DEFAULT NULL
        )');
        $this->addSql('CREATE INDEX idx_shooting_request_client ON shooting_request (client_id)');
        $this->addSql('CREATE INDEX idx_shooting_request_date ON shooting_request (shooting_date)');

        $this->addSql('CREATE TABLE shooting_request_video (
            shooting_request_id INT NOT NULL REFERENCES shooting_request(id) ON DELETE CASCADE,
            content_id INT NOT NULL REFERENCES content(id) ON DELETE CASCADE,
            PRIMARY KEY (shooting_request_id, content_id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shooting_request_video');
        $this->addSql('DROP TABLE shooting_request');
    }
}
