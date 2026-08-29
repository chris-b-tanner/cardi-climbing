<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add approved_at/approved_by and cancelled_at/cancelled_by to user_certification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_certification
            ADD approved_at    DATETIME DEFAULT NULL,
            ADD approved_by_id INT      DEFAULT NULL,
            ADD cancelled_at   DATETIME DEFAULT NULL,
            ADD cancelled_by_id INT     DEFAULT NULL');

        $this->addSql('ALTER TABLE user_certification
            ADD CONSTRAINT FK_user_cert_approved_by  FOREIGN KEY (approved_by_id)  REFERENCES `user` (id) ON DELETE SET NULL,
            ADD CONSTRAINT FK_user_cert_cancelled_by FOREIGN KEY (cancelled_by_id) REFERENCES `user` (id) ON DELETE SET NULL');

        $this->addSql('CREATE INDEX IDX_USER_CERT_APPROVED_BY  ON user_certification (approved_by_id)');
        $this->addSql('CREATE INDEX IDX_USER_CERT_CANCELLED_BY ON user_certification (cancelled_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_certification DROP FOREIGN KEY FK_user_cert_approved_by');
        $this->addSql('ALTER TABLE user_certification DROP FOREIGN KEY FK_user_cert_cancelled_by');
        $this->addSql('ALTER TABLE user_certification
            DROP approved_at, DROP approved_by_id,
            DROP cancelled_at, DROP cancelled_by_id');
    }
}
