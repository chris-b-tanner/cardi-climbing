<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow declaration.certification_id to be null, so removing an in-use declaration can detach rather than delete it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration DROP FOREIGN KEY FK_declaration_cert');
        $this->addSql('ALTER TABLE declaration MODIFY certification_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration ADD CONSTRAINT FK_declaration_cert FOREIGN KEY (certification_id) REFERENCES certification (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration DROP FOREIGN KEY FK_declaration_cert');
        $this->addSql('ALTER TABLE declaration MODIFY certification_id INT NOT NULL');
        $this->addSql('ALTER TABLE declaration ADD CONSTRAINT FK_declaration_cert FOREIGN KEY (certification_id) REFERENCES certification (id) ON DELETE CASCADE');
    }
}
