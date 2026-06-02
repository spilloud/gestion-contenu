<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602095100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Client accounts: prevent ROLE_USER leakage via legacy user.role';
    }

    public function up(Schema $schema): void
    {
        // Si le JSON roles contient ROLE_CLIENT, on force le champ legacy role à ROLE_CLIENT
        // pour éviter que User::getRoles() ne ré-injecte ROLE_USER depuis legacy.
        $this->addSql("UPDATE \"user\" SET role = 'ROLE_CLIENT' WHERE roles::text LIKE '%ROLE_CLIENT%'");
    }

    public function down(Schema $schema): void
    {
        // Retour au rôle legacy par défaut
        $this->addSql("UPDATE \"user\" SET role = 'ROLE_USER' WHERE role = 'ROLE_CLIENT'");
    }
}

