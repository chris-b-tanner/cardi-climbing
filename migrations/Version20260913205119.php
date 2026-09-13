<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260913205119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add door_log table for the door-access-firmware-spec.md diagnostic log endpoint.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE door_log (id INT AUTO_INCREMENT NOT NULL, door_id INT DEFAULT 1 NOT NULL, log_id VARCHAR(36) NOT NULL, level VARCHAR(10) NOT NULL, category VARCHAR(20) NOT NULL, reason VARCHAR(30) NOT NULL, message VARCHAR(255) NOT NULL, context JSON DEFAULT NULL, timestamp DATETIME NOT NULL, received_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_D5DDED27EA675D86 (log_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE door_log');
    }
}
