<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Asana mapping fields (client project gid, user gid, content task gid)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD asana_project_gid VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD asana_user_gid VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD asana_task_gid VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_content_asana_task_gid ON content (asana_task_gid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_content_asana_task_gid');
        $this->addSql('ALTER TABLE content DROP asana_task_gid');
        $this->addSql('ALTER TABLE "user" DROP asana_user_gid');
        $this->addSql('ALTER TABLE client DROP asana_project_gid');
    }
}

