<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add attendee.checked_out_at/checked_out_method for the exit reader, and an access_event.card_uid index for the exit_cards sync — see door-access-spec.md § Exit reader.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendee ADD checked_out_at DATETIME DEFAULT NULL, ADD checked_out_method VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_access_event_card_uid_authorized_at ON access_event (card_uid, authorized_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_access_event_card_uid_authorized_at ON access_event');
        $this->addSql('ALTER TABLE attendee DROP checked_out_at, DROP checked_out_method');
    }
}
