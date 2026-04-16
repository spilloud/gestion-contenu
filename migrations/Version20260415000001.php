<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add video workflow fields to content, add content_comment table, add video format and new statuses';
    }

    public function up(Schema $schema): void
    {
        // Content: add nullable video-specific columns to avoid breaking existing rows
        $this->addSql('ALTER TABLE content ADD video_has_subtitles BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD video_editor_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD video_rushes_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD video_edit_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD video_edit_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD video_submagic_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD video_final_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD video_final_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD video_thumbnail_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD video_caption TEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE content ADD CONSTRAINT FK_content_video_editor FOREIGN KEY (video_editor_id) REFERENCES "user" (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_content_video_editor_id ON content (video_editor_id)');

        // Content comments (discussion/retours in-app)
        $this->addSql('CREATE TABLE content_comment (id SERIAL PRIMARY KEY, content_id INT NOT NULL REFERENCES content(id) ON DELETE CASCADE, author_id INT DEFAULT NULL REFERENCES "user"(id) ON DELETE SET NULL, message TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX IDX_content_comment_content_id ON content_comment (content_id)');
        $this->addSql('CREATE INDEX IDX_content_comment_author_id ON content_comment (author_id)');

        // Add "vidéo" format (non-destructif)
        $this->addSql('INSERT INTO format (name, sort_order)
            SELECT \'vidéo\', 5
            WHERE NOT EXISTS (SELECT 1 FROM format WHERE lower(name) IN (\'video\', \'vidéo\'))
        ');

        // Add video-specific statuses (non-destructif)
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'Brouillon (Dérush)\', \'gray\', 12
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'Brouillon (Dérush)\')
        ');
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'Rushs / à dispatcher\', \'orange\', 13
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'Rushs / à dispatcher\')
        ');
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'À valider (Prod)\', \'yellow\', 14
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'À valider (Prod)\')
        ');
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'Retouches (Monteur)\', \'orange\', 15
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'Retouches (Monteur)\')
        ');
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'À valider (CM)\', \'yellow\', 16
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'À valider (CM)\')
        ');
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'Sous-titrage (SubMagic)\', \'yellow\', 17
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'Sous-titrage (SubMagic)\')
        ');
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'Prépa CM (sans sous-titres)\', \'yellow\', 18
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'Prépa CM (sans sous-titres)\')
        ');
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'À valider (Client)\', \'lightgreen\', 19
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'À valider (Client)\')
        ');
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'Prête à programmer\', \'lightgreen\', 20
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'Prête à programmer\')
        ');
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'Programmée\', \'lightgreen\', 21
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'Programmée\')
        ');
        $this->addSql('INSERT INTO status (name, color, sort_order)
            SELECT \'Publiée\', \'green\', 22
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = \'Publiée\')
        ');
    }

    public function down(Schema $schema): void
    {
        // drop new table first
        $this->addSql('DROP TABLE content_comment');

        // remove FK + index then columns
        $this->addSql('ALTER TABLE content DROP CONSTRAINT FK_content_video_editor');
        $this->addSql('DROP INDEX IDX_content_video_editor_id');
        $this->addSql('ALTER TABLE content DROP COLUMN video_has_subtitles');
        $this->addSql('ALTER TABLE content DROP COLUMN video_editor_id');
        $this->addSql('ALTER TABLE content DROP COLUMN video_rushes_url');
        $this->addSql('ALTER TABLE content DROP COLUMN video_edit_url');
        $this->addSql('ALTER TABLE content DROP COLUMN video_edit_filename');
        $this->addSql('ALTER TABLE content DROP COLUMN video_submagic_url');
        $this->addSql('ALTER TABLE content DROP COLUMN video_final_url');
        $this->addSql('ALTER TABLE content DROP COLUMN video_final_filename');
        $this->addSql('ALTER TABLE content DROP COLUMN video_thumbnail_url');
        $this->addSql('ALTER TABLE content DROP COLUMN video_caption');

        // Keep inserted format/status rows (non destructive down to avoid data loss)
    }
}

