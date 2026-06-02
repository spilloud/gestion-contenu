<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602094000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Client accounts: user↔client read-only access mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client_user_access (user_id INT NOT NULL, client_id INT NOT NULL, PRIMARY KEY(user_id, client_id))');
        $this->addSql('CREATE INDEX idx_client_user_access_user ON client_user_access (user_id)');
        $this->addSql('CREATE INDEX idx_client_user_access_client ON client_user_access (client_id)');
        $this->addSql('ALTER TABLE client_user_access ADD CONSTRAINT fk_client_user_access_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE client_user_access ADD CONSTRAINT fk_client_user_access_client FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE client_user_access');
    }
}

