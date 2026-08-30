<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add parent/dependent relationship between members';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD COLUMN parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_user_parent FOREIGN KEY (parent_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_USER_PARENT ON `user` (parent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_user_parent');
        $this->addSql('DROP INDEX IDX_USER_PARENT ON `user`');
        $this->addSql('ALTER TABLE `user` DROP COLUMN parent_id');
    }
}
