<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250316000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: user, community_manager, client, status, format, content, client_page, todo_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "user" (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, role VARCHAR(50) NOT NULL DEFAULT \'ROLE_USER\', created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE TABLE community_manager (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, email VARCHAR(255) DEFAULT NULL)');
        $this->addSql('CREATE TABLE status (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, color VARCHAR(50) NOT NULL, sort_order INT NOT NULL DEFAULT 0)');
        $this->addSql('CREATE TABLE format (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL, sort_order INT NOT NULL DEFAULT 0)');
        $this->addSql('CREATE TABLE client (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, community_manager_id INT NOT NULL REFERENCES community_manager(id))');
        $this->addSql('CREATE TABLE content (id SERIAL PRIMARY KEY, title VARCHAR(255) NOT NULL, scheduled_date DATE NOT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, client_id INT NOT NULL REFERENCES client(id), format_id INT NOT NULL REFERENCES format(id), status_id INT NOT NULL REFERENCES status(id))');
        $this->addSql('CREATE TABLE client_page (id SERIAL PRIMARY KEY, important_info TEXT DEFAULT NULL, ideas TEXT DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, client_id INT NOT NULL UNIQUE REFERENCES client(id))');
        $this->addSql('CREATE TABLE todo_item (id SERIAL PRIMARY KEY, label VARCHAR(500) NOT NULL, done BOOLEAN NOT NULL DEFAULT false, sort_order INT NOT NULL DEFAULT 0, client_page_id INT NOT NULL REFERENCES client_page(id))');

        $this->addSql('INSERT INTO status (name, color, sort_order) VALUES
            (\'À déterminer\', \'gray\', 1),
            (\'Tournage à prévoir\', \'red\', 2),
            (\'Montage à faire\', \'orange\', 3),
            (\'Design à faire\', \'orange\', 4),
            (\'Montage en cours\', \'orange\', 5),
            (\'Sous-titres à valider\', \'yellow\', 6),
            (\'Légende à faire\', \'yellow\', 7),
            (\'Miniature à faire\', \'yellow\', 8),
            (\'Contenu à faire valider\', \'lightgreen\', 9),
            (\'Metricool\', \'lightgreen\', 10),
            (\'OK\', \'green\', 11)
        ');
        $this->addSql('INSERT INTO format (name, sort_order) VALUES
            (\'réel\', 1),
            (\'post\', 2),
            (\'carrousel\', 3),
            (\'story\', 4)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE todo_item');
        $this->addSql('DROP TABLE client_page');
        $this->addSql('DROP TABLE content');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE format');
        $this->addSql('DROP TABLE status');
        $this->addSql('DROP TABLE community_manager');
        $this->addSql('DROP TABLE "user"');
    }
}
