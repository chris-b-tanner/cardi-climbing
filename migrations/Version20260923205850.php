<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923205850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add note.email_subject — tracks the ad hoc email conversation subject for both admin and inbound-reply notes, see ContactQuickEmailMailer.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note ADD email_subject VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note DROP email_subject');
    }
}
