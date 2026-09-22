<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922002227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace user.card_uid with access_card + card_link_session — see card-setup.md § "Why two real tables, not a field on user".';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE access_card (id INT AUTO_INCREMENT NOT NULL, uid VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, deployed_at DATETIME NOT NULL, locked_at DATETIME DEFAULT NULL, unlocked_at DATETIME DEFAULT NULL, replaced_at DATETIME DEFAULT NULL, user_id INT NOT NULL, deployed_by_id INT DEFAULT NULL, locked_by_id INT DEFAULT NULL, unlocked_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_E0CDFED5539B0606 (uid), INDEX IDX_E0CDFED5A76ED395 (user_id), INDEX IDX_E0CDFED5239114D2 (deployed_by_id), INDEX IDX_E0CDFED57A88E00 (locked_by_id), INDEX IDX_E0CDFED5371F3A6E (unlocked_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE card_link_session (id INT AUTO_INCREMENT NOT NULL, mode VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, scanned_uid VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, user_id INT NOT NULL, matched_user_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_BD1F2124A76ED395 (user_id), INDEX IDX_BD1F212437E277DD (matched_user_id), INDEX IDX_BD1F2124B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE access_card ADD CONSTRAINT FK_E0CDFED5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_card ADD CONSTRAINT FK_E0CDFED5239114D2 FOREIGN KEY (deployed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE access_card ADD CONSTRAINT FK_E0CDFED57A88E00 FOREIGN KEY (locked_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE access_card ADD CONSTRAINT FK_E0CDFED5371F3A6E FOREIGN KEY (unlocked_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE card_link_session ADD CONSTRAINT FK_BD1F2124A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE card_link_session ADD CONSTRAINT FK_BD1F212437E277DD FOREIGN KEY (matched_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE card_link_session ADD CONSTRAINT FK_BD1F2124B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX UNIQ_8D93D64936B82C7 ON user');
        $this->addSql('ALTER TABLE user DROP card_uid');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_card DROP FOREIGN KEY FK_E0CDFED5A76ED395');
        $this->addSql('ALTER TABLE access_card DROP FOREIGN KEY FK_E0CDFED5239114D2');
        $this->addSql('ALTER TABLE access_card DROP FOREIGN KEY FK_E0CDFED57A88E00');
        $this->addSql('ALTER TABLE access_card DROP FOREIGN KEY FK_E0CDFED5371F3A6E');
        $this->addSql('ALTER TABLE card_link_session DROP FOREIGN KEY FK_BD1F2124A76ED395');
        $this->addSql('ALTER TABLE card_link_session DROP FOREIGN KEY FK_BD1F212437E277DD');
        $this->addSql('ALTER TABLE card_link_session DROP FOREIGN KEY FK_BD1F2124B03A8386');
        $this->addSql('DROP TABLE access_card');
        $this->addSql('DROP TABLE card_link_session');
        $this->addSql('ALTER TABLE `user` ADD card_uid VARCHAR(96) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D64936B82C7 ON `user` (card_uid)');
    }
}
