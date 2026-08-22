<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add attendee_info column to event';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD attendee_info LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP attendee_info');
    }
}
