<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add note.email_ref, note.email_opened_at and note.email_open_count for Postmark open tracking — see EmailOpenTracking / WebhookController::postmarkOpen().';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note ADD email_ref VARCHAR(32) DEFAULT NULL, ADD email_opened_at DATETIME DEFAULT NULL, ADD email_open_count INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_note_email_ref ON note (email_ref)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_note_email_ref ON note');
        $this->addSql('ALTER TABLE note DROP email_ref, DROP email_opened_at, DROP email_open_count');
    }
}
