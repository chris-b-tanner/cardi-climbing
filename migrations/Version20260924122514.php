<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924122514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tag.color — an admin-chosen hex badge colour, see Tag::getEffectiveColor().';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tag ADD color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tag DROP color');
    }
}
