<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renumber user IDs to start from 1000 and set AUTO_INCREMENT accordingly';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        $this->addSql('UPDATE `user`                  SET id          = id          + 999');
        $this->addSql('UPDATE `note`                  SET user_id     = user_id     + 999');
        $this->addSql('UPDATE `note`                  SET added_by_id = added_by_id + 999 WHERE added_by_id IS NOT NULL');
        $this->addSql('UPDATE `user_tag`              SET user_id     = user_id     + 999');
        $this->addSql('UPDATE `reset_password_request` SET user_id   = user_id     + 999');

        // MySQL will use MAX(id)+1 if that is higher than 1000, which it will be after renumbering
        $this->addSql('ALTER TABLE `user` AUTO_INCREMENT = 1000');

        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        $this->addSql('UPDATE `reset_password_request` SET user_id   = user_id     - 999');
        $this->addSql('UPDATE `user_tag`              SET user_id     = user_id     - 999');
        $this->addSql('UPDATE `note`                  SET added_by_id = added_by_id - 999 WHERE added_by_id IS NOT NULL');
        $this->addSql('UPDATE `note`                  SET user_id     = user_id     - 999');
        $this->addSql('UPDATE `user`                  SET id          = id          - 999');

        $this->addSql('ALTER TABLE `user` AUTO_INCREMENT = 1');

        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}
