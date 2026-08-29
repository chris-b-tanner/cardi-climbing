<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add declaration table and signature/agreed-declarations to user_certification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE declaration (
            id                INT      NOT NULL AUTO_INCREMENT,
            certification_id  INT      NOT NULL,
            text              LONGTEXT NOT NULL,
            sort_order        INT      NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_DECLARATION_CERT (certification_id),
            CONSTRAINT FK_declaration_cert FOREIGN KEY (certification_id) REFERENCES certification (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE user_certification_declaration (
            user_certification_id INT NOT NULL,
            declaration_id         INT NOT NULL,
            PRIMARY KEY(user_certification_id, declaration_id),
            INDEX IDX_UC_DECL_UC (user_certification_id),
            INDEX IDX_UC_DECL_DECL (declaration_id),
            CONSTRAINT FK_uc_decl_uc   FOREIGN KEY (user_certification_id) REFERENCES user_certification (id) ON DELETE CASCADE,
            CONSTRAINT FK_uc_decl_decl FOREIGN KEY (declaration_id)        REFERENCES declaration (id)        ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE user_certification ADD signature LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_certification DROP signature');
        $this->addSql('DROP TABLE user_certification_declaration');
        $this->addSql('DROP TABLE declaration');
    }
}
