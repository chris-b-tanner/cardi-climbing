<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260914211504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email table (draft/approved/sent bulk-email workflow) and note.email_id link.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE email (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, use_blank_layout TINYINT DEFAULT 0 NOT NULL, audience_type VARCHAR(20) NOT NULL, audience_params JSON DEFAULT NULL, audience_label VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, approved_at DATETIME DEFAULT NULL, sent_at DATETIME DEFAULT NULL, sent_count INT DEFAULT NULL, created_by_id INT DEFAULT NULL, approved_by_id INT DEFAULT NULL, INDEX IDX_E7927C74B03A8386 (created_by_id), INDEX IDX_E7927C742D234F6A (approved_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE email ADD CONSTRAINT FK_E7927C74B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE email ADD CONSTRAINT FK_E7927C742D234F6A FOREIGN KEY (approved_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE note ADD email_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14A832C1C9 FOREIGN KEY (email_id) REFERENCES email (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CFBDFA14A832C1C9 ON note (email_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email DROP FOREIGN KEY FK_E7927C74B03A8386');
        $this->addSql('ALTER TABLE email DROP FOREIGN KEY FK_E7927C742D234F6A');
        $this->addSql('DROP TABLE email');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14A832C1C9');
        $this->addSql('DROP INDEX IDX_CFBDFA14A832C1C9 ON note');
        $this->addSql('ALTER TABLE note DROP email_id');
    }
}
