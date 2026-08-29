<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add event staffing requirements and staffing fields on attendee';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_staffing_requirement (
            id                INT      NOT NULL AUTO_INCREMENT,
            event_id          INT      NOT NULL,
            certification_id  INT      NOT NULL,
            min_count         INT      NOT NULL DEFAULT 1,
            created_at        DATETIME NOT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_STAFFING_EVENT_CERT (event_id, certification_id),
            INDEX IDX_STAFFING_REQUIREMENT_EVENT (event_id),
            INDEX IDX_STAFFING_REQUIREMENT_CERT (certification_id),
            CONSTRAINT FK_staffing_requirement_event FOREIGN KEY (event_id)         REFERENCES event (id)         ON DELETE CASCADE,
            CONSTRAINT FK_staffing_requirement_cert   FOREIGN KEY (certification_id) REFERENCES certification (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE `attendee` ADD COLUMN staffing_requirement_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `attendee` ADD COLUMN staffing_status VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE `attendee` ADD CONSTRAINT FK_attendee_staffing_requirement FOREIGN KEY (staffing_requirement_id) REFERENCES event_staffing_requirement (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_ATTENDEE_STAFFING_REQUIREMENT ON `attendee` (staffing_requirement_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `attendee` DROP FOREIGN KEY FK_attendee_staffing_requirement');
        $this->addSql('DROP INDEX IDX_ATTENDEE_STAFFING_REQUIREMENT ON `attendee`');
        $this->addSql('ALTER TABLE `attendee` DROP COLUMN staffing_requirement_id');
        $this->addSql('ALTER TABLE `attendee` DROP COLUMN staffing_status');

        $this->addSql('DROP TABLE event_staffing_requirement');
    }
}
