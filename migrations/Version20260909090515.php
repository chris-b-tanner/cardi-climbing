<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260909090515 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE magic_link (id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(32) NOT NULL, hashed_verifier VARCHAR(64) NOT NULL, redirect_path VARCHAR(255) DEFAULT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_6B40B1C69692E25D (selector), INDEX IDX_6B40B1C6A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE magic_link ADD CONSTRAINT FK_6B40B1C6A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE magic_link DROP FOREIGN KEY FK_6B40B1C6A76ED395');
        $this->addSql('DROP TABLE magic_link');
    }
}
