<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create news_post table and seed first community update';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE news_post (
            id            INT  NOT NULL AUTO_INCREMENT,
            author_id     INT  DEFAULT NULL,
            title         VARCHAR(255) NOT NULL,
            slug          VARCHAR(255) NOT NULL,
            body          LONGTEXT NOT NULL,
            published_at  DATETIME DEFAULT NULL,
            created_at    DATETIME NOT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_NEWS_POST_SLUG (slug),
            INDEX IDX_NEWS_POST_AUTHOR (author_id),
            CONSTRAINT FK_news_post_author FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $body = <<<'HTML'
<p>Now that summer is over and people are back from travels, it's time for us to accelerate plans for Y Wal &ndash; the Cardigan Climbing Wall.</p>

<p>We're going to kick off with two things:</p>

<ul>
<li>A meeting for anyone who is keen to use the facility, keen to volunteer, or is otherwise keen to be involved in any way.</li>
<li>A survey to gauge interest and desires from the community.</li>
</ul>

<h3>The meeting</h3>

<p><strong>Where?</strong> The Tabernacle (old chapel), High Street, Cardigan</p>
<p><strong>When?</strong> Date TBC, 6pm to 9pm</p>
<p><strong>What?</strong> We'll start off with a quick overview of where we are so far, and then progress to round-table discussions on various topics such as construction, layout, membership options, volunteering, funding and so on. Everyone will move around the tables so that the wall can benefit from community input on every aspect of the project.</p>

<p>Spaces are limited to XX people, so please express your interest beforehand by selecting &ldquo;yes&rdquo; on the community meetup question on the survey. We'll present the results on the night as well.</p>

<p>There will be cakes and drinks available for a small charge, which will be the first official fundraiser for the wall!</p>

<h3>The survey</h3>

<p>Just 5 minutes to get your thoughts! Submit the form once for you, and again for each of your children / dependents.</p>
HTML;

        $this->addSql(
            'INSERT INTO news_post (title, slug, body, published_at, created_at) VALUES (?, ?, ?, ?, ?)',
            ["Let's get the ball rolling!", 'lets-get-the-ball-rolling', $body, '2026-08-21 09:00:00', '2026-08-21 09:00:00']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE news_post');
    }
}
