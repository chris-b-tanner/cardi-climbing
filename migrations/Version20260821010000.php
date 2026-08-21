<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create certification, event, attendee, user_certification (induction) tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE certification (
            id   INT          NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_CERTIFICATION_NAME (name)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE event (
            id            INT          NOT NULL AUTO_INCREMENT,
            author_id     INT          DEFAULT NULL,
            title         VARCHAR(255) NOT NULL,
            description   LONGTEXT     DEFAULT NULL,
            date          DATE         NOT NULL,
            time_from     VARCHAR(5)   NOT NULL,
            time_to       VARCHAR(5)   NOT NULL,
            max_attendees INT          DEFAULT NULL,
            price         NUMERIC(8, 2) DEFAULT NULL,
            location      VARCHAR(255) NOT NULL,
            external_url  VARCHAR(255) DEFAULT NULL,
            status        VARCHAR(20)  NOT NULL,
            is_recurring  TINYINT(1)   NOT NULL,
            recur_until   DATE         DEFAULT NULL,
            recur_days    VARCHAR(20)  DEFAULT NULL,
            created_at    DATETIME     NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_EVENT_AUTHOR (author_id),
            CONSTRAINT FK_event_author FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE attendee (
            id              INT           NOT NULL AUTO_INCREMENT,
            event_id        INT           NOT NULL,
            user_id         INT           NOT NULL,
            added_by_id     INT           DEFAULT NULL,
            occurrence_date DATE          DEFAULT NULL,
            status          VARCHAR(20)   NOT NULL,
            price           NUMERIC(8, 2) DEFAULT NULL,
            paid_amount     NUMERIC(8, 2) NOT NULL DEFAULT \'0.00\',
            created_at      DATETIME      NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_ATTENDEE_EVENT (event_id),
            INDEX IDX_ATTENDEE_USER (user_id),
            INDEX IDX_ATTENDEE_ADDED_BY (added_by_id),
            CONSTRAINT FK_attendee_event    FOREIGN KEY (event_id)    REFERENCES event (id)   ON DELETE CASCADE,
            CONSTRAINT FK_attendee_user     FOREIGN KEY (user_id)     REFERENCES `user` (id)  ON DELETE CASCADE,
            CONSTRAINT FK_attendee_added_by FOREIGN KEY (added_by_id) REFERENCES `user` (id)  ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE event_certification (
            event_id         INT NOT NULL,
            certification_id INT NOT NULL,
            PRIMARY KEY(event_id, certification_id),
            INDEX IDX_EVENT_CERT_EVENT (event_id),
            INDEX IDX_EVENT_CERT_CERT (certification_id),
            CONSTRAINT FK_event_cert_event FOREIGN KEY (event_id)         REFERENCES event (id)         ON DELETE CASCADE,
            CONSTRAINT FK_event_cert_cert  FOREIGN KEY (certification_id) REFERENCES certification (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE user_certification (
            id                INT      NOT NULL AUTO_INCREMENT,
            user_id           INT      NOT NULL,
            certification_id  INT      NOT NULL,
            started_at        DATETIME NOT NULL,
            started_by_id     INT      DEFAULT NULL,
            completed_at      DATETIME DEFAULT NULL,
            completed_by_id   INT      DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX IDX_USER_CERT_USER (user_id),
            INDEX IDX_USER_CERT_CERT (certification_id),
            INDEX IDX_USER_CERT_STARTED_BY (started_by_id),
            INDEX IDX_USER_CERT_COMPLETED_BY (completed_by_id),
            CONSTRAINT FK_user_cert_user          FOREIGN KEY (user_id)          REFERENCES `user` (id)        ON DELETE CASCADE,
            CONSTRAINT FK_user_cert_cert          FOREIGN KEY (certification_id) REFERENCES certification (id) ON DELETE CASCADE,
            CONSTRAINT FK_user_cert_started_by    FOREIGN KEY (started_by_id)    REFERENCES `user` (id)        ON DELETE SET NULL,
            CONSTRAINT FK_user_cert_completed_by  FOREIGN KEY (completed_by_id)  REFERENCES `user` (id)        ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("INSERT INTO certification (name) VALUES ('Climbing'), ('Bouldering')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_certification');
        $this->addSql('DROP TABLE event_certification');
        $this->addSql('DROP TABLE attendee');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE certification');
    }
}
