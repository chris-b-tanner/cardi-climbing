<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260913202350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add access_event table and user.keyholder_pin for the door-access-spec.md keyholder/access-event log design.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE access_event (id INT AUTO_INCREMENT NOT NULL, door_id INT DEFAULT 1 NOT NULL, event_id VARCHAR(36) NOT NULL, type VARCHAR(20) NOT NULL, stage VARCHAR(20) DEFAULT NULL, denied_reason VARCHAR(20) DEFAULT NULL, authorized_at DATETIME DEFAULT NULL, door_open_at DATETIME DEFAULT NULL, door_closed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, attendee_id INT DEFAULT NULL, keyholder_user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_D23B76F471F7E88B (event_id), INDEX IDX_D23B76F4BCFD782A (attendee_id), INDEX IDX_D23B76F4387BBC5A (keyholder_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE access_event ADD CONSTRAINT FK_D23B76F4BCFD782A FOREIGN KEY (attendee_id) REFERENCES attendee (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE access_event ADD CONSTRAINT FK_D23B76F4387BBC5A FOREIGN KEY (keyholder_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user ADD keyholder_pin VARCHAR(6) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649680645B1 ON user (keyholder_pin)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE access_event DROP FOREIGN KEY FK_D23B76F4BCFD782A');
        $this->addSql('ALTER TABLE access_event DROP FOREIGN KEY FK_D23B76F4387BBC5A');
        $this->addSql('DROP TABLE access_event');
        $this->addSql('DROP INDEX UNIQ_8D93D649680645B1 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP keyholder_pin');
    }
}
