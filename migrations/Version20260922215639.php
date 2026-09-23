<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922215639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add access_card.all_hours_access — standing door access with no booking required, see door-access-spec.md § All-hours cards.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_card ADD all_hours_access TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_card DROP all_hours_access');
    }
}
