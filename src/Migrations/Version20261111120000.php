<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Organisation des distributions : créneaux de retrait des commandes, lieu,
 * postes et inscriptions de bénévoles, rappels.
 */
final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Créneaux de retrait, bénévolat et rappels des distributions';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE jour_distrib ADD lieu VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE TABLE creneau_retrait (id INT AUTO_INCREMENT NOT NULL, debut DATETIME NOT NULL, fin DATETIME NOT NULL, places INT NOT NULL, jour_distrib_id INT NOT NULL, INDEX IDX_30520A2564949231 (jour_distrib_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE creneau_retrait ADD CONSTRAINT FK_30520A2564949231 FOREIGN KEY (jour_distrib_id) REFERENCES jour_distrib (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commande ADD rappel_envoye_le DATETIME DEFAULT NULL, ADD creneau_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67D7D0729A9 FOREIGN KEY (creneau_id) REFERENCES creneau_retrait (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6EEAA67D7D0729A9 ON commande (creneau_id)');
        $this->addSql('CREATE TABLE poste_benevole (id INT AUTO_INCREMENT NOT NULL, intitule VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, debut DATETIME NOT NULL, fin DATETIME NOT NULL, besoin INT NOT NULL, jour_distrib_id INT NOT NULL, INDEX IDX_447E66BD64949231 (jour_distrib_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE poste_benevole ADD CONSTRAINT FK_447E66BD64949231 FOREIGN KEY (jour_distrib_id) REFERENCES jour_distrib (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE inscription_benevole (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, present TINYINT DEFAULT 0 NOT NULL, rappel_envoye_le DATETIME DEFAULT NULL, poste_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX inscription_benevole_unique (poste_id, user_id), INDEX IDX_1D94307EA0905086 (poste_id), INDEX IDX_1D94307EA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE inscription_benevole ADD CONSTRAINT FK_1D94307EA0905086 FOREIGN KEY (poste_id) REFERENCES poste_benevole (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE inscription_benevole ADD CONSTRAINT FK_1D94307EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP TABLE inscription_benevole');
        $this->addSql('DROP TABLE poste_benevole');
        $this->addSql('ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67D7D0729A9');
        $this->addSql('DROP INDEX IDX_6EEAA67D7D0729A9 ON commande');
        $this->addSql('ALTER TABLE commande DROP rappel_envoye_le, DROP creneau_id');
        $this->addSql('DROP TABLE creneau_retrait');
        $this->addSql('ALTER TABLE jour_distrib DROP lieu');
    }
}
