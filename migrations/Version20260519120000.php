<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260519120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal des actions contenu, workflows statuts (standard/vidéo), nettoyage statuts obsolètes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE content_action_log (
            id SERIAL PRIMARY KEY,
            content_id INT NOT NULL REFERENCES content(id) ON DELETE CASCADE,
            action_type VARCHAR(64) NOT NULL,
            label VARCHAR(500) NOT NULL,
            detail TEXT DEFAULT NULL,
            user_id INT DEFAULT NULL REFERENCES "user"(id) ON DELETE SET NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE INDEX idx_content_action_log_content_created ON content_action_log (content_id, created_at)');

        $this->addSql("ALTER TABLE status ADD workflow VARCHAR(16) NOT NULL DEFAULT 'standard'");

        $this->addSql("UPDATE status SET workflow = 'video' WHERE name IN (
            'Brouillon (Dérush)', 'Rushs / à dispatcher', 'Montage à faire', 'Montage en cours',
            'Retouches (Monteur)', 'À valider (Prod)', 'Sous-titrage (SubMagic)', 'Prépa CM (sans sous-titres)',
            'Sous-titres à valider', 'À valider (CM)', 'À valider (Client)', 'À faire valider au client',
            'Prête à programmer', 'Programmée'
        )");
        $this->addSql("UPDATE status SET workflow = 'both' WHERE name = 'Publiée'");

        $this->addSql("INSERT INTO status (name, color, sort_order, workflow)
            SELECT 'Brouillon (idée)', 'gray', 5, 'standard'
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = 'Brouillon (idée)')");
        $this->addSql("INSERT INTO status (name, color, sort_order, workflow)
            SELECT 'En préparation', 'orange', 6, 'standard'
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = 'En préparation')");
        $this->addSql("INSERT INTO status (name, color, sort_order, workflow)
            SELECT 'À valider (post)', 'yellow', 7, 'standard'
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = 'À valider (post)')");
        $this->addSql("INSERT INTO status (name, color, sort_order, workflow)
            SELECT 'Prêt à publier', 'lightgreen', 8, 'standard'
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = 'Prêt à publier')");

        $this->addSql("UPDATE status SET sort_order = 100, workflow = 'video' WHERE name = 'Brouillon (Dérush)'");
        $this->addSql("UPDATE status SET sort_order = 110, workflow = 'video' WHERE name = 'Rushs / à dispatcher'");
        $this->addSql("UPDATE status SET sort_order = 120, workflow = 'video' WHERE name = 'Montage à faire'");
        $this->addSql("UPDATE status SET sort_order = 130, workflow = 'video' WHERE name = 'Montage en cours'");
        $this->addSql("UPDATE status SET sort_order = 140, workflow = 'video' WHERE name = 'Retouches (Monteur)'");
        $this->addSql("UPDATE status SET sort_order = 150, workflow = 'video' WHERE name = 'À valider (Prod)'");
        $this->addSql("UPDATE status SET sort_order = 160, workflow = 'video' WHERE name = 'Sous-titrage (SubMagic)'");
        $this->addSql("UPDATE status SET sort_order = 161, workflow = 'video' WHERE name = 'Prépa CM (sans sous-titres)'");
        $this->addSql("UPDATE status SET sort_order = 170, workflow = 'video' WHERE name = 'Sous-titres à valider'");
        $this->addSql("UPDATE status SET sort_order = 180, workflow = 'video' WHERE name = 'À valider (CM)'");
        $this->addSql("UPDATE status SET sort_order = 190, workflow = 'video' WHERE name = 'À valider (Client)'");
        $this->addSql("UPDATE status SET sort_order = 191, workflow = 'video' WHERE name = 'À faire valider au client'");
        $this->addSql("UPDATE status SET sort_order = 200, workflow = 'video' WHERE name = 'Prête à programmer'");
        $this->addSql("UPDATE status SET sort_order = 210, workflow = 'video' WHERE name = 'Programmée'");
        $this->addSql("UPDATE status SET sort_order = 220, workflow = 'both' WHERE name = 'Publiée'");

        $this->addSql("UPDATE status SET sort_order = 10, workflow = 'standard' WHERE name = 'Brouillon (idée)'");
        $this->addSql("UPDATE status SET sort_order = 20, workflow = 'standard' WHERE name = 'En préparation'");
        $this->addSql("UPDATE status SET sort_order = 30, workflow = 'standard' WHERE name = 'À valider (post)'");
        $this->addSql("UPDATE status SET sort_order = 40, workflow = 'standard' WHERE name = 'Prêt à publier'");

        // Remap posts (non-vidéo) depuis anciens statuts
        $this->addSql("UPDATE content c SET status_id = (SELECT id FROM status WHERE name = 'Brouillon (idée)' LIMIT 1)
            FROM format f WHERE c.format_id = f.id AND LOWER(f.name) NOT IN ('vidéo', 'video')
            AND c.status_id IN (SELECT id FROM status WHERE name IN ('À déterminer'))");
        $this->addSql("UPDATE content c SET status_id = (SELECT id FROM status WHERE name = 'En préparation' LIMIT 1)
            FROM format f WHERE c.format_id = f.id AND LOWER(f.name) NOT IN ('vidéo', 'video')
            AND c.status_id IN (SELECT id FROM status WHERE name IN ('Tournage à prévoir', 'Design à faire', 'Montage à faire', 'Montage en cours'))");
        $this->addSql("UPDATE content c SET status_id = (SELECT id FROM status WHERE name = 'À valider (post)' LIMIT 1)
            FROM format f WHERE c.format_id = f.id AND LOWER(f.name) NOT IN ('vidéo', 'video')
            AND c.status_id IN (SELECT id FROM status WHERE name IN ('Sous-titres à valider', 'Légende à faire', 'Contenu à faire valider'))");
        $this->addSql("UPDATE content c SET status_id = (SELECT id FROM status WHERE name = 'Prêt à publier' LIMIT 1)
            FROM format f WHERE c.format_id = f.id AND LOWER(f.name) NOT IN ('vidéo', 'video')
            AND c.status_id IN (SELECT id FROM status WHERE name IN ('Miniature à faire', 'Metricool'))");
        $this->addSql("UPDATE content c SET status_id = (SELECT id FROM status WHERE name = 'Publiée' LIMIT 1)
            FROM format f WHERE c.format_id = f.id AND LOWER(f.name) NOT IN ('vidéo', 'video')
            AND c.status_id IN (SELECT id FROM status WHERE name IN ('OK'))");

        $this->addSql("DELETE FROM status WHERE name IN (
            'À déterminer', 'Tournage à prévoir', 'Design à faire', 'Légende à faire',
            'Miniature à faire', 'Contenu à faire valider', 'Metricool', 'OK'
        ) AND NOT EXISTS (SELECT 1 FROM content WHERE status_id = status.id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content_action_log');
        $this->addSql('ALTER TABLE status DROP workflow');
    }
}
