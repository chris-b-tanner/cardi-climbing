<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904214243 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add membership_type and membership tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE membership (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, status VARCHAR(20) NOT NULL, price NUMERIC(8, 2) NOT NULL, paid_amount NUMERIC(8, 2) DEFAULT \'0.00\' NOT NULL, auto_renew TINYINT DEFAULT 0 NOT NULL, user_id INT NOT NULL, membership_type_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_86FFD285A76ED395 (user_id), INDEX IDX_86FFD2854CE11AC2 (membership_type_id), INDEX IDX_86FFD285B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE membership_type (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, price NUMERIC(8, 2) NOT NULL, duration VARCHAR(10) NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, is_family TINYINT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_F7E162E25E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD2854CE11AC2 FOREIGN KEY (membership_type_id) REFERENCES membership_type (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership DROP FOREIGN KEY FK_86FFD285A76ED395');
        $this->addSql('ALTER TABLE membership DROP FOREIGN KEY FK_86FFD2854CE11AC2');
        $this->addSql('ALTER TABLE membership DROP FOREIGN KEY FK_86FFD285B03A8386');
        $this->addSql('DROP TABLE membership');
        $this->addSql('DROP TABLE membership_type');
    }
}
