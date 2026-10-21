<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_watcher — team members watching a contact (User::$watchers), emailed about new notes, replies and emails sent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_watcher (
            user_id    INT NOT NULL,
            watcher_id INT NOT NULL,
            PRIMARY KEY(user_id, watcher_id),
            INDEX IDX_EA82F8A6A76ED395 (user_id),
            INDEX IDX_EA82F8A6C300AB5D (watcher_id),
            CONSTRAINT FK_EA82F8A6A76ED395 FOREIGN KEY (user_id)    REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_EA82F8A6C300AB5D FOREIGN KEY (watcher_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_watcher');
    }
}
