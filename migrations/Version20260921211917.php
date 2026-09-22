<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921211917 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen user.card_uid to fit CardScanService\'s transient link/verify sentinel values — see card-setup.md.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user CHANGE card_uid card_uid VARCHAR(96) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` CHANGE card_uid card_uid VARCHAR(32) DEFAULT NULL');
    }
}
