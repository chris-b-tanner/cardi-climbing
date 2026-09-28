<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924123601 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product.image_s3_key — an admin-assigned product photo, see ProductImageUploader.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD image_s3_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP image_s3_key');
    }
}
