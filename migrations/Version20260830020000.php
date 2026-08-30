<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add archive (soft-delete) support to members';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD COLUMN deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD COLUMN deleted_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_user_deleted_by FOREIGN KEY (deleted_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_USER_DELETED_BY ON `user` (deleted_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_user_deleted_by');
        $this->addSql('DROP INDEX IDX_USER_DELETED_BY ON `user`');
        $this->addSql('ALTER TABLE `user` DROP COLUMN deleted_by_id');
        $this->addSql('ALTER TABLE `user` DROP COLUMN deleted_at');
    }
}
