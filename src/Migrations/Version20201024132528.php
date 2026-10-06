<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201024132528 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE jour_distrib_pain DROP FOREIGN KEY FK_AC6AC5FE64775A84');
        $this->addSql('ALTER TABLE ligne_commande DROP FOREIGN KEY FK_3170B74B64775A84');
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, conditionnement DOUBLE PRECISION NOT NULL, prix_init DOUBLE PRECISION NOT NULL, prix_final DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE product_jour_distrib (product_id INT NOT NULL, jour_distrib_id INT NOT NULL, INDEX IDX_FFD0B1624584665A (product_id), INDEX IDX_FFD0B16264949231 (jour_distrib_id), PRIMARY KEY(product_id, jour_distrib_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE product_jour_distrib ADD CONSTRAINT FK_FFD0B1624584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_jour_distrib ADD CONSTRAINT FK_FFD0B16264949231 FOREIGN KEY (jour_distrib_id) REFERENCES jour_distrib (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE jour_distrib_pain');
        $this->addSql('DROP TABLE pain');
        $this->addSql('ALTER TABLE commande CHANGE jour_distrib_id jour_distrib_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE jour_distrib CHANGE poid_restant poid_restant DOUBLE PRECISION DEFAULT NULL, CHANGE closed closed TINYINT(1) DEFAULT NULL');
        $this->addSql('ALTER TABLE user CHANGE roles roles JSON NOT NULL');
        $this->addSql('DROP INDEX IDX_3170B74B64775A84 ON ligne_commande');
        $this->addSql('ALTER TABLE ligne_commande ADD product_id INT DEFAULT NULL, DROP pain_id, CHANGE commande_id commande_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ligne_commande ADD CONSTRAINT FK_3170B74B4584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('CREATE INDEX IDX_3170B74B4584665A ON ligne_commande (product_id)');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE product_jour_distrib DROP FOREIGN KEY FK_FFD0B1624584665A');
        $this->addSql('ALTER TABLE ligne_commande DROP FOREIGN KEY FK_3170B74B4584665A');
        $this->addSql('CREATE TABLE jour_distrib_pain (jour_distrib_id INT NOT NULL, pain_id INT NOT NULL, INDEX IDX_AC6AC5FE64949231 (jour_distrib_id), INDEX IDX_AC6AC5FE64775A84 (pain_id), PRIMARY KEY(jour_distrib_id, pain_id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE pain (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, poid DOUBLE PRECISION NOT NULL, prix DOUBLE PRECISION NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE jour_distrib_pain ADD CONSTRAINT FK_AC6AC5FE64775A84 FOREIGN KEY (pain_id) REFERENCES pain (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jour_distrib_pain ADD CONSTRAINT FK_AC6AC5FE64949231 FOREIGN KEY (jour_distrib_id) REFERENCES jour_distrib (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_jour_distrib');
        $this->addSql('ALTER TABLE commande CHANGE jour_distrib_id jour_distrib_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE jour_distrib CHANGE poid_restant poid_restant DOUBLE PRECISION DEFAULT \'NULL\', CHANGE closed closed TINYINT(1) DEFAULT \'NULL\'');
        $this->addSql('DROP INDEX IDX_3170B74B4584665A ON ligne_commande');
        $this->addSql('ALTER TABLE ligne_commande ADD pain_id INT DEFAULT NULL, DROP product_id, CHANGE commande_id commande_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ligne_commande ADD CONSTRAINT FK_3170B74B64775A84 FOREIGN KEY (pain_id) REFERENCES pain (id)');
        $this->addSql('CREATE INDEX IDX_3170B74B64775A84 ON ligne_commande (pain_id)');
        $this->addSql('ALTER TABLE user CHANGE roles roles LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_bin`');
    }
}
