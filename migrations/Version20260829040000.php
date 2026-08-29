<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create payment and refund tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment (
            id                        INT           NOT NULL AUTO_INCREMENT,
            user_id                   INT           NOT NULL,
            attendee_id               INT           DEFAULT NULL,
            taken_by_id               INT           DEFAULT NULL,
            amount                    NUMERIC(8, 2) NOT NULL,
            currency                  VARCHAR(3)    NOT NULL DEFAULT \'gbp\',
            method                    VARCHAR(20)   NOT NULL,
            stripe_payment_intent_id  VARCHAR(255)  DEFAULT NULL,
            created_at                DATETIME      NOT NULL,
            succeeded_at              DATETIME      DEFAULT NULL,
            failed_at                 DATETIME      DEFAULT NULL,
            failure_reason            VARCHAR(255)  DEFAULT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_PAYMENT_STRIPE_PAYMENT_INTENT_ID (stripe_payment_intent_id),
            INDEX IDX_PAYMENT_USER (user_id),
            INDEX IDX_PAYMENT_ATTENDEE (attendee_id),
            INDEX IDX_PAYMENT_TAKEN_BY (taken_by_id),
            CONSTRAINT FK_payment_user     FOREIGN KEY (user_id)     REFERENCES `user` (id)  ON DELETE CASCADE,
            CONSTRAINT FK_payment_attendee FOREIGN KEY (attendee_id) REFERENCES attendee (id) ON DELETE SET NULL,
            CONSTRAINT FK_payment_taken_by FOREIGN KEY (taken_by_id) REFERENCES `user` (id)  ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE refund (
            id                INT           NOT NULL AUTO_INCREMENT,
            payment_id        INT           NOT NULL,
            created_by_id     INT           DEFAULT NULL,
            amount            NUMERIC(8, 2) NOT NULL,
            reason            VARCHAR(255)  DEFAULT NULL,
            stripe_refund_id  VARCHAR(255)  DEFAULT NULL,
            created_at        DATETIME      NOT NULL,
            succeeded_at      DATETIME      DEFAULT NULL,
            failed_at         DATETIME      DEFAULT NULL,
            failure_reason    VARCHAR(255)  DEFAULT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_REFUND_STRIPE_REFUND_ID (stripe_refund_id),
            INDEX IDX_REFUND_PAYMENT (payment_id),
            INDEX IDX_REFUND_CREATED_BY (created_by_id),
            CONSTRAINT FK_refund_payment    FOREIGN KEY (payment_id)    REFERENCES payment (id) ON DELETE CASCADE,
            CONSTRAINT FK_refund_created_by FOREIGN KEY (created_by_id) REFERENCES `user` (id)  ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE refund');
        $this->addSql('DROP TABLE payment');
    }
}
