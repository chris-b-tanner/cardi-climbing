<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260914214131 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the Email approval stage — approved_by/approved_at become sent_by (approved_at was redundant with sent_at).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email DROP FOREIGN KEY `FK_E7927C742D234F6A`');
        $this->addSql('DROP INDEX IDX_E7927C742D234F6A ON email');
        $this->addSql('ALTER TABLE email DROP approved_at, CHANGE approved_by_id sent_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD CONSTRAINT FK_E7927C74A45BB98C FOREIGN KEY (sent_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E7927C74A45BB98C ON email (sent_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email DROP FOREIGN KEY FK_E7927C74A45BB98C');
        $this->addSql('DROP INDEX IDX_E7927C74A45BB98C ON email');
        $this->addSql('ALTER TABLE email ADD approved_at DATETIME DEFAULT NULL, CHANGE sent_by_id approved_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD CONSTRAINT `FK_E7927C742D234F6A` FOREIGN KEY (approved_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E7927C742D234F6A ON email (approved_by_id)');
    }
}
