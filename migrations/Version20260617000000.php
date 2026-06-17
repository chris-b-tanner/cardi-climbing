<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email2 and email3 alternate email fields to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user`
            ADD COLUMN email2 VARCHAR(180) DEFAULT NULL,
            ADD COLUMN email3 VARCHAR(180) DEFAULT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user`
            DROP COLUMN email2,
            DROP COLUMN email3
        ');
    }
}
