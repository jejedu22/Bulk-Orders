<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Newsletter réservée aux clients d'une vente.
 */
final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter : ciblage des clients d\'une vente (jour_distrib_id)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE newsletter ADD jour_distrib_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE newsletter ADD CONSTRAINT FK_7E8585C864949231 FOREIGN KEY (jour_distrib_id) REFERENCES jour_distrib (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7E8585C864949231 ON newsletter (jour_distrib_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE newsletter DROP FOREIGN KEY FK_7E8585C864949231');
        $this->addSql('DROP INDEX IDX_7E8585C864949231 ON newsletter');
        $this->addSql('ALTER TABLE newsletter DROP jour_distrib_id');
    }
}
