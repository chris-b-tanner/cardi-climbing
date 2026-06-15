<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tag and user_tag tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tag (
            id   INT          NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY UNIQ_389B7835E237E06 (name)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE user_tag (
            user_id INT NOT NULL,
            tag_id  INT NOT NULL,
            PRIMARY KEY(user_id, tag_id),
            INDEX IDX_E89FD608A76ED395 (user_id),
            INDEX IDX_E89FD608BAD26311 (tag_id),
            CONSTRAINT FK_user_tag_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_user_tag_tag  FOREIGN KEY (tag_id)  REFERENCES tag   (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_tag');
        $this->addSql('DROP TABLE tag');
    }
}
