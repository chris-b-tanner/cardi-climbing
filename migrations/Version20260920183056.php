<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920183056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add User.assignedTo — lets a contact be assigned to a team member from the contact view.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD assigned_to_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649F4BD7827 ON user (assigned_to_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649F4BD7827');
        $this->addSql('DROP INDEX IDX_8D93D649F4BD7827 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP assigned_to_id');
    }
}
