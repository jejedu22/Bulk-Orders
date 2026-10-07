<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Historique des envois de newsletters, par utilisateur.
 */
final class Version20261008110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historique des envois de newsletters (newsletter_delivery)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE newsletter_delivery (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(255) NOT NULL, sent_at DATETIME NOT NULL, error LONGTEXT DEFAULT NULL, test TINYINT DEFAULT 0 NOT NULL, newsletter_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_EA832A7A96E4F388 (sent_at), INDEX IDX_EA832A7A22DB1917 (newsletter_id), INDEX IDX_EA832A7AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE newsletter_delivery ADD CONSTRAINT FK_EA832A7A22DB1917 FOREIGN KEY (newsletter_id) REFERENCES newsletter (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_delivery ADD CONSTRAINT FK_EA832A7AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP TABLE newsletter_delivery');
    }
}
