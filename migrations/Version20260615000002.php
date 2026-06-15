<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add phone/address fields to user; create note table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user`
            ADD COLUMN phone         VARCHAR(30)  DEFAULT NULL,
            ADD COLUMN address_line1 VARCHAR(255) DEFAULT NULL,
            ADD COLUMN address_line2 VARCHAR(255) DEFAULT NULL,
            ADD COLUMN town          VARCHAR(100) DEFAULT NULL,
            ADD COLUMN postcode      VARCHAR(20)  DEFAULT NULL
        ');

        $this->addSql('CREATE TABLE note (
            id           INT  NOT NULL AUTO_INCREMENT,
            user_id      INT  NOT NULL,
            added_by_id  INT  DEFAULT NULL,
            content      LONGTEXT NOT NULL,
            created_at   DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_NOTE_USER     (user_id),
            INDEX IDX_NOTE_ADDED_BY (added_by_id),
            CONSTRAINT FK_note_user     FOREIGN KEY (user_id)     REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_note_added_by FOREIGN KEY (added_by_id) REFERENCES `user` (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE note');
        $this->addSql('ALTER TABLE `user`
            DROP COLUMN phone,
            DROP COLUMN address_line1,
            DROP COLUMN address_line2,
            DROP COLUMN town,
            DROP COLUMN postcode
        ');
    }
}
