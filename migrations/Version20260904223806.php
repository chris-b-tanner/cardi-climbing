<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904223806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sales_order, sales_order_row and credit_ledger_entry; link payment/refund/attendee to them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sales_order (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_36D222EA76ED395 (user_id), INDEX IDX_36D222EB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sales_order_row (id INT AUTO_INCREMENT NOT NULL, qty INT NOT NULL, list_price_at_sale NUMERIC(8, 2) NOT NULL, charged_price NUMERIC(8, 2) NOT NULL, vat_code_at_sale VARCHAR(20) NOT NULL, note VARCHAR(255) DEFAULT NULL, order_id INT NOT NULL, product_id INT NOT NULL, beneficiary_member_id INT NOT NULL, INDEX IDX_FB25E1F28D9F6D38 (order_id), INDEX IDX_FB25E1F24584665A (product_id), INDEX IDX_FB25E1F23A7EFF3F (beneficiary_member_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE credit_ledger_entry (id INT AUTO_INCREMENT NOT NULL, credit_change INT NOT NULL, reason VARCHAR(20) NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, sales_order_row_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_42D23023A76ED395 (user_id), INDEX IDX_42D23023DC60749F (sales_order_row_id), INDEX IDX_42D23023B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sales_order ADD CONSTRAINT FK_36D222EA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sales_order ADD CONSTRAINT FK_36D222EB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sales_order_row ADD CONSTRAINT FK_FB25E1F28D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sales_order_row ADD CONSTRAINT FK_FB25E1F24584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE sales_order_row ADD CONSTRAINT FK_FB25E1F23A7EFF3F FOREIGN KEY (beneficiary_member_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE credit_ledger_entry ADD CONSTRAINT FK_42D23023A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE credit_ledger_entry ADD CONSTRAINT FK_42D23023DC60749F FOREIGN KEY (sales_order_row_id) REFERENCES sales_order_row (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE credit_ledger_entry ADD CONSTRAINT FK_42D23023B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment ADD order_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D8D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6D28840D8D9F6D38 ON payment (order_id)');
        $this->addSql('ALTER TABLE refund ADD sales_order_row_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE refund ADD CONSTRAINT FK_5B2C1458DC60749F FOREIGN KEY (sales_order_row_id) REFERENCES sales_order_row (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5B2C1458DC60749F ON refund (sales_order_row_id)');
        $this->addSql('ALTER TABLE attendee ADD sales_order_row_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE attendee ADD CONSTRAINT FK_1150D567DC60749F FOREIGN KEY (sales_order_row_id) REFERENCES sales_order_row (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1150D567DC60749F ON attendee (sales_order_row_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP FOREIGN KEY FK_36D222EA76ED395');
        $this->addSql('ALTER TABLE sales_order DROP FOREIGN KEY FK_36D222EB03A8386');
        $this->addSql('ALTER TABLE sales_order_row DROP FOREIGN KEY FK_FB25E1F28D9F6D38');
        $this->addSql('ALTER TABLE sales_order_row DROP FOREIGN KEY FK_FB25E1F24584665A');
        $this->addSql('ALTER TABLE sales_order_row DROP FOREIGN KEY FK_FB25E1F23A7EFF3F');
        $this->addSql('ALTER TABLE credit_ledger_entry DROP FOREIGN KEY FK_42D23023A76ED395');
        $this->addSql('ALTER TABLE credit_ledger_entry DROP FOREIGN KEY FK_42D23023DC60749F');
        $this->addSql('ALTER TABLE credit_ledger_entry DROP FOREIGN KEY FK_42D23023B03A8386');
        $this->addSql('DROP TABLE credit_ledger_entry');
        $this->addSql('DROP TABLE sales_order');
        $this->addSql('DROP TABLE sales_order_row');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D8D9F6D38');
        $this->addSql('DROP INDEX IDX_6D28840D8D9F6D38 ON payment');
        $this->addSql('ALTER TABLE payment DROP order_id');
        $this->addSql('ALTER TABLE refund DROP FOREIGN KEY FK_5B2C1458DC60749F');
        $this->addSql('DROP INDEX IDX_5B2C1458DC60749F ON refund');
        $this->addSql('ALTER TABLE refund DROP sales_order_row_id');
        $this->addSql('ALTER TABLE attendee DROP FOREIGN KEY FK_1150D567DC60749F');
        $this->addSql('DROP INDEX IDX_1150D567DC60749F ON attendee');
        $this->addSql('ALTER TABLE attendee DROP sales_order_row_id');
    }
}
