<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427102000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Asana subtitles review task gid on content';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content ADD asana_subtitles_task_gid VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_content_asana_subtitles_task_gid ON content (asana_subtitles_task_gid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_content_asana_subtitles_task_gid');
        $this->addSql('ALTER TABLE content DROP asana_subtitles_task_gid');
    }
}

