<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vidéo : délégation CM et relecteur sous-titres (réassignation Asana)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content ADD video_cm_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD CONSTRAINT FK_VIDEO_CM_USER FOREIGN KEY (video_cm_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_VIDEO_CM_USER ON content (video_cm_user_id)');

        $this->addSql('ALTER TABLE content ADD video_subtitles_reviewer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD CONSTRAINT FK_VIDEO_SUBTITLES_REVIEWER FOREIGN KEY (video_subtitles_reviewer_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_VIDEO_SUBTITLES_REVIEWER ON content (video_subtitles_reviewer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content DROP CONSTRAINT FK_VIDEO_SUBTITLES_REVIEWER');
        $this->addSql('DROP INDEX IDX_VIDEO_SUBTITLES_REVIEWER');
        $this->addSql('ALTER TABLE content DROP video_subtitles_reviewer_id');

        $this->addSql('ALTER TABLE content DROP CONSTRAINT FK_VIDEO_CM_USER');
        $this->addSql('DROP INDEX IDX_VIDEO_CM_USER');
        $this->addSql('ALTER TABLE content DROP video_cm_user_id');
    }
}
