<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260906092816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link inventory_movement to the sales_order_row that caused it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_movement ADD sales_order_row_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE inventory_movement ADD CONSTRAINT FK_40972F66DC60749F FOREIGN KEY (sales_order_row_id) REFERENCES sales_order_row (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_40972F66DC60749F ON inventory_movement (sales_order_row_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_movement DROP FOREIGN KEY FK_40972F66DC60749F');
        $this->addSql('DROP INDEX IDX_40972F66DC60749F ON inventory_movement');
        $this->addSql('ALTER TABLE inventory_movement DROP sales_order_row_id');
    }
}
