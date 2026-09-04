<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904215800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product and its type extension tables (stock, credit, membership, event ticket) plus inventory_movement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE credit_product (credits_granted INT NOT NULL, product_id INT NOT NULL, PRIMARY KEY (product_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE event_ticket_product (product_id INT NOT NULL, event_id INT NOT NULL, INDEX IDX_C2EB8C71F7E88B (event_id), PRIMARY KEY (product_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE inventory_movement (id INT AUTO_INCREMENT NOT NULL, quantity_change INT NOT NULL, net_price NUMERIC(8, 2) DEFAULT NULL, reason VARCHAR(20) NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, stock_product_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_40972F66EBCD91F6 (stock_product_id), INDEX IDX_40972F66B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE membership_product (product_id INT NOT NULL, membership_type_id INT NOT NULL, INDEX IDX_5AE792FC4CE11AC2 (membership_type_id), PRIMARY KEY (product_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, short_description VARCHAR(255) DEFAULT NULL, variant_name VARCHAR(100) DEFAULT NULL, variant_value VARCHAR(100) DEFAULT NULL, price NUMERIC(8, 2) NOT NULL, vat_code VARCHAR(20) NOT NULL, product_type VARCHAR(20) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stock_product (cost_price NUMERIC(8, 2) NOT NULL, sku VARCHAR(64) NOT NULL, product_id INT NOT NULL, UNIQUE INDEX UNIQ_CAEC140EF9038C4 (sku), PRIMARY KEY (product_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE credit_product ADD CONSTRAINT FK_E9CE63824584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event_ticket_product ADD CONSTRAINT FK_C2EB8C4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event_ticket_product ADD CONSTRAINT FK_C2EB8C71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE inventory_movement ADD CONSTRAINT FK_40972F66EBCD91F6 FOREIGN KEY (stock_product_id) REFERENCES stock_product (product_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE inventory_movement ADD CONSTRAINT FK_40972F66B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE membership_product ADD CONSTRAINT FK_5AE792FC4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE membership_product ADD CONSTRAINT FK_5AE792FC4CE11AC2 FOREIGN KEY (membership_type_id) REFERENCES membership_type (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE stock_product ADD CONSTRAINT FK_CAEC140E4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE credit_product DROP FOREIGN KEY FK_E9CE63824584665A');
        $this->addSql('ALTER TABLE event_ticket_product DROP FOREIGN KEY FK_C2EB8C4584665A');
        $this->addSql('ALTER TABLE event_ticket_product DROP FOREIGN KEY FK_C2EB8C71F7E88B');
        $this->addSql('ALTER TABLE inventory_movement DROP FOREIGN KEY FK_40972F66EBCD91F6');
        $this->addSql('ALTER TABLE inventory_movement DROP FOREIGN KEY FK_40972F66B03A8386');
        $this->addSql('ALTER TABLE membership_product DROP FOREIGN KEY FK_5AE792FC4584665A');
        $this->addSql('ALTER TABLE membership_product DROP FOREIGN KEY FK_5AE792FC4CE11AC2');
        $this->addSql('ALTER TABLE stock_product DROP FOREIGN KEY FK_CAEC140E4584665A');
        $this->addSql('DROP TABLE credit_product');
        $this->addSql('DROP TABLE event_ticket_product');
        $this->addSql('DROP TABLE inventory_movement');
        $this->addSql('DROP TABLE membership_product');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE stock_product');
    }
}
