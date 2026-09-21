<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921171751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add User.createdBy — records which admin added a contact (null for self-registration).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD created_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649B03A8386 ON user (created_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649B03A8386');
        $this->addSql('DROP INDEX IDX_8D93D649B03A8386 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP created_by_id');
    }
}
