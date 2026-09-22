<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922163300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make card_link_session.user_id nullable, for MODE_LOOKUP ("find by card") sessions that have no target member — see card-setup.md.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_link_session CHANGE user_id user_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_link_session CHANGE user_id user_id INT NOT NULL');
    }
}
