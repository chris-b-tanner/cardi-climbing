<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904230103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make sales_order_row.beneficiary_member_id nullable and add occurrence_date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order_row DROP FOREIGN KEY FK_FB25E1F23A7EFF3F');
        $this->addSql('ALTER TABLE sales_order_row ADD occurrence_date DATE DEFAULT NULL, CHANGE beneficiary_member_id beneficiary_member_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sales_order_row ADD CONSTRAINT FK_FB25E1F23A7EFF3F FOREIGN KEY (beneficiary_member_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order_row DROP FOREIGN KEY FK_FB25E1F23A7EFF3F');
        $this->addSql('ALTER TABLE sales_order_row DROP occurrence_date, CHANGE beneficiary_member_id beneficiary_member_id INT NOT NULL');
        $this->addSql('ALTER TABLE sales_order_row ADD CONSTRAINT FK_FB25E1F23A7EFF3F FOREIGN KEY (beneficiary_member_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }
}
