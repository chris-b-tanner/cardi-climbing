<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921195858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.card_uid for membership card NFC tap entry — see door-access-spec.md § Card-based entry (NFC).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD card_uid VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D64936B82C7 ON user (card_uid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D64936B82C7 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP card_uid');
    }
}
