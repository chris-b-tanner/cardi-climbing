<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchase orders: purchase_order, its lines, receipts and invoices; User::isSupplier and supplierAccountReference; Asset::supplier and purchaseOrderLine.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE purchase_order (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(20) DEFAULT NULL, status VARCHAR(20) NOT NULL, supplier_reference VARCHAR(100) DEFAULT NULL, expected_at DATE DEFAULT NULL, delivery_charge NUMERIC(8, 2) NOT NULL, delivery_vat_code VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, approved_at DATETIME DEFAULT NULL, ordered_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, cancel_reason VARCHAR(255) DEFAULT NULL, supplier_id INT NOT NULL, created_by_id INT DEFAULT NULL, submitted_by_id INT DEFAULT NULL, approved_by_id INT DEFAULT NULL, ordered_by_id INT DEFAULT NULL, cancelled_by_id INT DEFAULT NULL, approval_task_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_21E210B296901F54 (number), INDEX IDX_21E210B22ADD6D8C (supplier_id), INDEX IDX_21E210B2B03A8386 (created_by_id), INDEX IDX_21E210B279F7D87D (submitted_by_id), INDEX IDX_21E210B22D234F6A (approved_by_id), INDEX IDX_21E210B291FF3C4D (ordered_by_id), INDEX IDX_21E210B2187B2D12 (cancelled_by_id), UNIQUE INDEX UNIQ_21E210B219F6BEC0 (approval_task_id), INDEX IDX_21E210B27B00651C (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE purchase_order_line (id INT AUTO_INCREMENT NOT NULL, description VARCHAR(255) NOT NULL, category VARCHAR(30) DEFAULT NULL, supplier_code VARCHAR(100) DEFAULT NULL, qty INT NOT NULL, unit_cost NUMERIC(8, 2) NOT NULL, vat_code VARCHAR(20) NOT NULL, position INT NOT NULL, purchase_order_id INT NOT NULL, stock_product_id INT DEFAULT NULL, INDEX IDX_90D6D92BA45D7E6A (purchase_order_id), INDEX IDX_90D6D92BEBCD91F6 (stock_product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE purchase_order_receipt (id INT AUTO_INCREMENT NOT NULL, qty INT NOT NULL, received_at DATETIME NOT NULL, average_cost_after NUMERIC(8, 2) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, line_id INT NOT NULL, received_by_id INT DEFAULT NULL, inventory_movement_id INT DEFAULT NULL, INDEX IDX_73F1A6114D7B7542 (line_id), INDEX IDX_73F1A6116F8DDD17 (received_by_id), UNIQUE INDEX UNIQ_73F1A611566C4C68 (inventory_movement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE purchase_order_invoice (id INT AUTO_INCREMENT NOT NULL, invoice_number VARCHAR(100) DEFAULT NULL, invoice_date DATE DEFAULT NULL, s3_key VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size_bytes INT NOT NULL, uploaded_at DATETIME NOT NULL, purchase_order_id INT NOT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_B00D0710A45D7E6A (purchase_order_id), INDEX IDX_B00D0710A2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE purchase_order_invoice ADD CONSTRAINT FK_B00D0710A45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE purchase_order_invoice ADD CONSTRAINT FK_B00D0710A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE purchase_order ADD CONSTRAINT FK_21E210B22ADD6D8C FOREIGN KEY (supplier_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE purchase_order ADD CONSTRAINT FK_21E210B2B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE purchase_order ADD CONSTRAINT FK_21E210B279F7D87D FOREIGN KEY (submitted_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE purchase_order ADD CONSTRAINT FK_21E210B22D234F6A FOREIGN KEY (approved_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE purchase_order ADD CONSTRAINT FK_21E210B291FF3C4D FOREIGN KEY (ordered_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE purchase_order ADD CONSTRAINT FK_21E210B2187B2D12 FOREIGN KEY (cancelled_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE purchase_order ADD CONSTRAINT FK_21E210B219F6BEC0 FOREIGN KEY (approval_task_id) REFERENCES note (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE purchase_order_receipt ADD CONSTRAINT FK_73F1A6114D7B7542 FOREIGN KEY (line_id) REFERENCES purchase_order_line (id)');
        $this->addSql('ALTER TABLE purchase_order_receipt ADD CONSTRAINT FK_73F1A6116F8DDD17 FOREIGN KEY (received_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE purchase_order_receipt ADD CONSTRAINT FK_73F1A611566C4C68 FOREIGN KEY (inventory_movement_id) REFERENCES inventory_movement (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE purchase_order_line ADD CONSTRAINT FK_90D6D92BA45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE purchase_order_line ADD CONSTRAINT FK_90D6D92BEBCD91F6 FOREIGN KEY (stock_product_id) REFERENCES stock_product (product_id)');
        $this->addSql('ALTER TABLE `user` ADD is_supplier TINYINT DEFAULT 0 NOT NULL, ADD supplier_account_reference VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD supplier_id INT DEFAULT NULL, ADD purchase_order_line_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD CONSTRAINT FK_2AF5A5C2ADD6D8C FOREIGN KEY (supplier_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE asset ADD CONSTRAINT FK_2AF5A5C6D136516 FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_line (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2AF5A5C2ADD6D8C ON asset (supplier_id)');
        $this->addSql('CREATE INDEX IDX_2AF5A5C6D136516 ON asset (purchase_order_line_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset DROP FOREIGN KEY FK_2AF5A5C2ADD6D8C');
        $this->addSql('ALTER TABLE asset DROP FOREIGN KEY FK_2AF5A5C6D136516');
        $this->addSql('DROP INDEX IDX_2AF5A5C2ADD6D8C ON asset');
        $this->addSql('DROP INDEX IDX_2AF5A5C6D136516 ON asset');
        $this->addSql('ALTER TABLE asset DROP supplier_id, DROP purchase_order_line_id');
        $this->addSql('ALTER TABLE `user` DROP is_supplier, DROP supplier_account_reference');
        $this->addSql('DROP TABLE purchase_order_invoice');
        $this->addSql('DROP TABLE purchase_order_receipt');
        $this->addSql('DROP TABLE purchase_order_line');
        $this->addSql('DROP TABLE purchase_order');
    }
}
