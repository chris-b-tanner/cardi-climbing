<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen certification.description from VARCHAR(255) to LONGTEXT so longer descriptions are not truncated.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE certification CHANGE description description LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE certification CHANGE description description VARCHAR(255) DEFAULT NULL');
    }
}
