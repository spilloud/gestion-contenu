<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password reset token fields on user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD password_reset_token VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD password_reset_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_PASSWORD_RESET_TOKEN ON "user" (password_reset_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_USER_PASSWORD_RESET_TOKEN');
        $this->addSql('ALTER TABLE "user" DROP password_reset_token');
        $this->addSql('ALTER TABLE "user" DROP password_reset_requested_at');
    }
}
