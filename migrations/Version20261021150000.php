<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create asset and asset_file — the asset register (things the centre owns and uses but doesn\'t sell) and its photos/documents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE asset (
            id                           INT AUTO_INCREMENT NOT NULL,
            name                         VARCHAR(150) NOT NULL,
            category                     VARCHAR(30) NOT NULL,
            description                  LONGTEXT DEFAULT NULL,
            quantity                     INT NOT NULL,
            identifier                   VARCHAR(100) DEFAULT NULL,
            location                     VARCHAR(100) DEFAULT NULL,
            status                       VARCHAR(20) NOT NULL,
            acquisition_type             VARCHAR(20) NOT NULL,
            acquired_at                  DATE DEFAULT NULL,
            purchase_cost                NUMERIC(10, 2) DEFAULT NULL,
            replacement_value            NUMERIC(10, 2) DEFAULT NULL,
            replacement_value_updated_at DATE DEFAULT NULL,
            warranty_until               DATE DEFAULT NULL,
            disposed_at                  DATE DEFAULT NULL,
            disposal_reason              VARCHAR(20) DEFAULT NULL,
            disposal_note                VARCHAR(255) DEFAULT NULL,
            created_at                   DATETIME NOT NULL,
            created_by_id                INT DEFAULT NULL,
            INDEX IDX_2AF5A5CB03A8386 (created_by_id),
            INDEX IDX_2AF5A5C7B00651C (status),
            INDEX IDX_2AF5A5C64C19C1 (category),
            PRIMARY KEY (id),
            CONSTRAINT FK_2AF5A5CB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE asset_file (
            id                INT AUTO_INCREMENT NOT NULL,
            s3_key            VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            mime_type         VARCHAR(100) NOT NULL,
            size_bytes        INT NOT NULL,
            kind              VARCHAR(20) NOT NULL,
            uploaded_at       DATETIME NOT NULL,
            asset_id          INT NOT NULL,
            uploaded_by_id    INT DEFAULT NULL,
            INDEX IDX_68FBF3D85DA1941 (asset_id),
            INDEX IDX_68FBF3D8A2B28FE8 (uploaded_by_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_68FBF3D85DA1941  FOREIGN KEY (asset_id)       REFERENCES asset (id)  ON DELETE CASCADE,
            CONSTRAINT FK_68FBF3D8A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE asset_file');
        $this->addSql('DROP TABLE asset');
    }
}
