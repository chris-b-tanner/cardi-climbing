<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow members to be recorded without an email address';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` MODIFY email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` MODIFY email VARCHAR(180) NOT NULL');
    }
}
