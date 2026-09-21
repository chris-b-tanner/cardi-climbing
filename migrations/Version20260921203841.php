<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921203841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add access_event.card_uid/card_user_id — identify who a denied or granted card tap belonged to even with no valid attendee (see door-access-spec.md § Card-based entry).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_event ADD card_uid VARCHAR(32) DEFAULT NULL, ADD card_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE access_event ADD CONSTRAINT FK_D23B76F4F07459E3 FOREIGN KEY (card_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D23B76F4F07459E3 ON access_event (card_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_event DROP FOREIGN KEY FK_D23B76F4F07459E3');
        $this->addSql('DROP INDEX IDX_D23B76F4F07459E3 ON access_event');
        $this->addSql('ALTER TABLE access_event DROP card_uid, DROP card_user_id');
    }
}
