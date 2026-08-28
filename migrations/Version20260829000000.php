<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add description column to certification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE certification ADD description VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE certification DROP description');
    }
}
