<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion des adhérents : adhésions (cotisations) des utilisateurs.
 */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adhésions des utilisateurs';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE adhesion (id INT AUTO_INCREMENT NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, montant DOUBLE PRECISION DEFAULT NULL, mode_paiement VARCHAR(20) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_C50CA65A1C22B544 (date_fin), INDEX IDX_C50CA65AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE adhesion ADD CONSTRAINT FK_C50CA65AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP TABLE adhesion');
    }
}
