<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260916164635 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Note.assignedTo — lets a pinned note be assigned to a team member.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE note ADD assigned_to_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CFBDFA14F4BD7827 ON note (assigned_to_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14F4BD7827');
        $this->addSql('DROP INDEX IDX_CFBDFA14F4BD7827 ON note');
        $this->addSql('ALTER TABLE note DROP assigned_to_id');
    }
}
