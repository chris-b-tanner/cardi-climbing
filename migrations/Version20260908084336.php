<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260908084336 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendee ADD pin VARCHAR(6) DEFAULT NULL, ADD pin_status VARCHAR(20) DEFAULT NULL, ADD checked_in_at DATETIME DEFAULT NULL, ADD checked_in_method VARCHAR(20) DEFAULT NULL, ADD checked_in_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE attendee ADD CONSTRAINT FK_1150D56742569552 FOREIGN KEY (checked_in_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1150D56742569552 ON attendee (checked_in_by_id)');
        $this->addSql('ALTER TABLE event ADD is_self_access TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendee DROP FOREIGN KEY FK_1150D56742569552');
        $this->addSql('DROP INDEX IDX_1150D56742569552 ON attendee');
        $this->addSql('ALTER TABLE attendee DROP pin, DROP pin_status, DROP checked_in_at, DROP checked_in_method, DROP checked_in_by_id');
        $this->addSql('ALTER TABLE event DROP is_self_access');
    }
}
