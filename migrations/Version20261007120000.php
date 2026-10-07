<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop attendee.pin/pin_status — door entry is card-only; the credential sync is now every confirmed booking on a self-access event.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendee DROP pin, DROP pin_status');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendee ADD pin VARCHAR(6) DEFAULT NULL, ADD pin_status VARCHAR(20) DEFAULT NULL');
    }
}
