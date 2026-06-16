<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anciens contenus (publication avant 01/06/2026) passés au statut Publiée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE content c SET status_id = (SELECT id FROM status WHERE name = 'Publiée' LIMIT 1)
            WHERE c.scheduled_date < '2026-06-01'
            AND c.status_id != (SELECT id FROM status WHERE name = 'Publiée' LIMIT 1)");
    }

    public function down(Schema $schema): void
    {
        // Irréversible : les anciens statuts ne sont pas conservés.
    }
}
