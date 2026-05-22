<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Community managers → utilisateurs ROLE_CM (client + fiche vidéo).
 */
final class Version20260522130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace community_manager par des utilisateurs ROLE_CM sur client et content';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD community_manager_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_CLIENT_COMMUNITY_MANAGER_USER FOREIGN KEY (community_manager_user_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_CLIENT_COMMUNITY_MANAGER_USER ON client (community_manager_user_id)');

        $this->addSql(<<<'SQL'
            UPDATE client cl
            SET community_manager_user_id = u.id
            FROM community_manager cm
            INNER JOIN "user" u ON LOWER(TRIM(u.email)) = LOWER(TRIM(cm.email))
            WHERE cm.id = cl.community_manager_id
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE client
            SET community_manager_user_id = (
                SELECT u.id FROM "user" u
                WHERE u.role = 'ROLE_CM' OR CAST(u.roles AS TEXT) LIKE '%"ROLE_CM"%'
                ORDER BY u.id ASC
                LIMIT 1
            )
            WHERE community_manager_user_id IS NULL
            SQL);

        $this->addSql('ALTER TABLE client DROP CONSTRAINT client_community_manager_id_fkey');
        $this->addSql('DROP INDEX IF EXISTS idx_client_community_manager');
        $this->addSql('ALTER TABLE client DROP COLUMN community_manager_id');
        $this->addSql('ALTER TABLE client ALTER community_manager_user_id SET NOT NULL');

        $this->addSql(<<<'SQL'
            UPDATE content co
            SET video_cm_user_id = u.id
            FROM community_manager cm
            INNER JOIN "user" u ON LOWER(TRIM(u.email)) = LOWER(TRIM(cm.email))
            WHERE cm.id = co.video_community_manager_id
              AND co.video_cm_user_id IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE content co
            SET video_cm_user_id = cl.community_manager_user_id
            FROM client cl
            WHERE cl.id = co.client_id
              AND co.video_cm_user_id IS NULL
            SQL);

        $this->addSql('ALTER TABLE content DROP CONSTRAINT IF EXISTS fk_video_community_manager');
        $this->addSql('ALTER TABLE content DROP CONSTRAINT IF EXISTS FK_VIDEO_COMMUNITY_MANAGER');
        $this->addSql('DROP INDEX IF EXISTS idx_video_community_manager');
        $this->addSql('DROP INDEX IF EXISTS IDX_VIDEO_COMMUNITY_MANAGER');
        $this->addSql('ALTER TABLE content DROP COLUMN IF EXISTS video_community_manager_id');

        $this->addSql('DROP TABLE community_manager');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_manager (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, email VARCHAR(255) DEFAULT NULL)');
        $this->addSql('ALTER TABLE client ADD community_manager_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD video_community_manager_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE client DROP CONSTRAINT FK_CLIENT_COMMUNITY_MANAGER_USER');
        $this->addSql('DROP INDEX IDX_CLIENT_COMMUNITY_MANAGER_USER');
        $this->addSql('ALTER TABLE client DROP COLUMN community_manager_user_id');
    }
}
