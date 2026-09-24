<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924064052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_file (admin-uploaded documents on a member profile) and user_certification.pdf_s3_key/pdf_generated_at (the stored completion certificate) — see UserFileUploader and CertificationPdfStorage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_file (id INT AUTO_INCREMENT NOT NULL, s3_key VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size_bytes INT NOT NULL, uploaded_at DATETIME NOT NULL, user_id INT NOT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_F61E7AD9A76ED395 (user_id), INDEX IDX_F61E7AD9A2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE user_file ADD CONSTRAINT FK_F61E7AD9A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_file ADD CONSTRAINT FK_F61E7AD9A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_certification ADD pdf_s3_key VARCHAR(255) DEFAULT NULL, ADD pdf_generated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_file DROP FOREIGN KEY FK_F61E7AD9A76ED395');
        $this->addSql('ALTER TABLE user_file DROP FOREIGN KEY FK_F61E7AD9A2B28FE8');
        $this->addSql('DROP TABLE user_file');
        $this->addSql('ALTER TABLE user_certification DROP pdf_s3_key, DROP pdf_generated_at');
    }
}
