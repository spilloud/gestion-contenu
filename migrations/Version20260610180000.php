<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Infos vidéaste (texte enrichi) sur les demandes de tournage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shooting_request ADD videographer_notes TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shooting_request DROP videographer_notes');
    }
}
