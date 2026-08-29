<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add date of birth and emergency contact fields to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD COLUMN date_of_birth DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD COLUMN emergency_contact_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD COLUMN emergency_contact_phone VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP COLUMN date_of_birth');
        $this->addSql('ALTER TABLE `user` DROP COLUMN emergency_contact_name');
        $this->addSql('ALTER TABLE `user` DROP COLUMN emergency_contact_phone');
    }
}
