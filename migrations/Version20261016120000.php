<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add note.email_scheduled_for for quick emails scheduled from the add-note form — see SendScheduledNoteEmailMessageHandler.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note ADD email_scheduled_for DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note DROP email_scheduled_for');
    }
}
