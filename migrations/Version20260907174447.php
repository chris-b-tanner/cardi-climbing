<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260907174447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY `FK_note_user`');
        $this->addSql('DROP INDEX IDX_CFBDFA14A76ED395 ON note');
        // noteable_type starts nullable so existing rows survive the ADD, then every existing note
        // is a member note (the only type that existed before this migration) before it's tightened
        // to NOT NULL. user_id is renamed (not dropped/re-added) so its values become noteable_id for free.
        $this->addSql('ALTER TABLE note ADD noteable_type VARCHAR(20) DEFAULT NULL, ADD pinned TINYINT DEFAULT 0 NOT NULL, ADD pinned_at DATETIME DEFAULT NULL, ADD pinned_by_id INT DEFAULT NULL, CHANGE user_id noteable_id INT NOT NULL');
        $this->addSql('UPDATE note SET noteable_type = \'member\'');
        $this->addSql('ALTER TABLE note CHANGE noteable_type noteable_type VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA1459662AC1 FOREIGN KEY (pinned_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CFBDFA1459662AC1 ON note (pinned_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA1459662AC1');
        $this->addSql('DROP INDEX IDX_CFBDFA1459662AC1 ON note');
        $this->addSql('ALTER TABLE note DROP noteable_type, DROP pinned, DROP pinned_at, DROP pinned_by_id, CHANGE noteable_id user_id INT NOT NULL');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT `FK_note_user` FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_CFBDFA14A76ED395 ON note (user_id)');
    }
}
