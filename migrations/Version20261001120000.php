<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.last_activity_at (backfilled from existing notes, payments, bookings, sales, memberships and certifications) and tag.remind_after_days — see LastActivityListener / Tag::isStale().';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD last_activity_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE tag ADD remind_after_days INT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE `user` u
            JOIN (
                SELECT user_id, MAX(at) AS at FROM (
                    SELECT noteable_id AS user_id, created_at AS at FROM note WHERE noteable_type = 'member'
                    UNION ALL
                    SELECT a.user_id, n.created_at FROM note n JOIN attendee a ON a.id = n.noteable_id WHERE n.noteable_type = 'attendee'
                    UNION ALL
                    SELECT o.user_id, n.created_at FROM note n JOIN sales_order o ON o.id = n.noteable_id WHERE n.noteable_type = 'order'
                    UNION ALL
                    SELECT user_id, created_at FROM payment
                    UNION ALL
                    SELECT user_id, created_at FROM attendee
                    UNION ALL
                    SELECT user_id, created_at FROM sales_order
                    UNION ALL
                    SELECT user_id, created_at FROM membership
                    UNION ALL
                    SELECT user_id, started_at FROM user_certification
                ) activity
                GROUP BY user_id
            ) latest ON latest.user_id = u.id
            SET u.last_activity_at = latest.at
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP last_activity_at');
        $this->addSql('ALTER TABLE tag DROP remind_after_days');
    }
}
