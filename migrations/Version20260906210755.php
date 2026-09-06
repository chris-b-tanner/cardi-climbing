<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260906210755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE credit_ledger_entry ADD attendee_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE credit_ledger_entry ADD CONSTRAINT FK_42D23023BCFD782A FOREIGN KEY (attendee_id) REFERENCES attendee (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_42D23023BCFD782A ON credit_ledger_entry (attendee_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE credit_ledger_entry DROP FOREIGN KEY FK_42D23023BCFD782A');
        $this->addSql('DROP INDEX IDX_42D23023BCFD782A ON credit_ledger_entry');
        $this->addSql('ALTER TABLE credit_ledger_entry DROP attendee_id');
    }
}
