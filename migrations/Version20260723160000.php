<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync Asana bidirectionnelle : dueOnLastPushedAt et tâches liées (dérush CM)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content ADD asana_montage_due_on_last_pushed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql('CREATE TABLE asana_linked_task (
            id SERIAL PRIMARY KEY,
            task_gid VARCHAR(64) NOT NULL,
            kind VARCHAR(32) NOT NULL,
            client_id INT NOT NULL REFERENCES client(id) ON DELETE CASCADE,
            content_ids JSON NOT NULL,
            completed_at_lucy TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_asana_linked_task_gid ON asana_linked_task (task_gid)');
        $this->addSql('CREATE INDEX idx_asana_linked_task_client ON asana_linked_task (client_id)');
        $this->addSql('CREATE INDEX idx_asana_linked_task_open ON asana_linked_task (completed_at_lucy)');

        $this->addSql('ALTER TABLE shooting_request ADD asana_task_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shooting_request DROP asana_task_completed_at');
        $this->addSql('DROP TABLE asana_linked_task');
        $this->addSql('ALTER TABLE content DROP asana_montage_due_on_last_pushed_at');
    }
}
