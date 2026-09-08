<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260907204819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event_ticket_product ADD membership_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE event_ticket_product ADD CONSTRAINT FK_C2EB8C4CE11AC2 FOREIGN KEY (membership_type_id) REFERENCES membership_type (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C2EB8C4CE11AC2 ON event_ticket_product (membership_type_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event_ticket_product DROP FOREIGN KEY FK_C2EB8C4CE11AC2');
        $this->addSql('DROP INDEX IDX_C2EB8C4CE11AC2 ON event_ticket_product');
        $this->addSql('ALTER TABLE event_ticket_product DROP membership_type_id');
    }
}
