<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260907180344 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note ADD completed_at DATETIME DEFAULT NULL, ADD completed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA1485ECDE76 FOREIGN KEY (completed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CFBDFA1485ECDE76 ON note (completed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA1485ECDE76');
        $this->addSql('DROP INDEX IDX_CFBDFA1485ECDE76 ON note');
        $this->addSql('ALTER TABLE note DROP completed_at, DROP completed_by_id');
    }
}
