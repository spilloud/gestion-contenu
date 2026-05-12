<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nouveau statut vidéo : après montage sans passage CM, en attente de validation client.
 */
final class Version20260512120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add video status « À faire valider au client » (insert at sort_order 19, shift following rows)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE status SET sort_order = sort_order + 1 WHERE sort_order >= 19');
        $this->addSql("INSERT INTO status (name, color, sort_order)
            SELECT 'À faire valider au client', 'lightgreen', 19
            WHERE NOT EXISTS (SELECT 1 FROM status WHERE name = 'À faire valider au client')
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM status WHERE name = 'À faire valider au client'");
        $this->addSql('UPDATE status SET sort_order = sort_order - 1 WHERE sort_order > 19');
    }
}
