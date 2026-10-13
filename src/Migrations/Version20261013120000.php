<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pièces jointes des newsletters et bouton « Commander ».
 */
final class Version20261013120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pièces jointes des newsletters (newsletter_attachment) et option « bouton Commander »';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE newsletter_attachment (id INT AUTO_INCREMENT NOT NULL, original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(64) NOT NULL, mime_type VARCHAR(127) NOT NULL, size INT NOT NULL, newsletter_id INT NOT NULL, UNIQUE INDEX UNIQ_944AE0111185AF6A (stored_name), INDEX IDX_944AE01122DB1917 (newsletter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE newsletter_attachment ADD CONSTRAINT FK_944AE01122DB1917 FOREIGN KEY (newsletter_id) REFERENCES newsletter (id) ON DELETE CASCADE');
        // Les newsletters existantes (brouillons) reçoivent le bouton, comme les nouvelles
        $this->addSql('ALTER TABLE newsletter ADD order_button TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE newsletter DROP order_button');
        $this->addSql('DROP TABLE newsletter_attachment');
    }
}
