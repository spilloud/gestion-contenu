<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vidéo : Community manager déléguée (remplace sélection utilisateur CM)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content ADD video_community_manager_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD CONSTRAINT FK_VIDEO_COMMUNITY_MANAGER FOREIGN KEY (video_community_manager_id) REFERENCES community_manager (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_VIDEO_COMMUNITY_MANAGER ON content (video_community_manager_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content DROP CONSTRAINT FK_VIDEO_COMMUNITY_MANAGER');
        $this->addSql('DROP INDEX IDX_VIDEO_COMMUNITY_MANAGER');
        $this->addSql('ALTER TABLE content DROP video_community_manager_id');
    }
}
