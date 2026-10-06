<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201024141322 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE jour_distrib_product (jour_distrib_id INT NOT NULL, product_id INT NOT NULL, INDEX IDX_75CC6E2264949231 (jour_distrib_id), INDEX IDX_75CC6E224584665A (product_id), PRIMARY KEY(jour_distrib_id, product_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE jour_distrib_product ADD CONSTRAINT FK_75CC6E2264949231 FOREIGN KEY (jour_distrib_id) REFERENCES jour_distrib (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jour_distrib_product ADD CONSTRAINT FK_75CC6E224584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE product_jour_distrib');
        $this->addSql('ALTER TABLE product CHANGE prix_final prix_final DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commande CHANGE jour_distrib_id jour_distrib_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE jour_distrib CHANGE poid_restant poid_restant DOUBLE PRECISION DEFAULT NULL, CHANGE closed closed TINYINT(1) DEFAULT NULL');
        $this->addSql('ALTER TABLE user CHANGE roles roles JSON NOT NULL');
        $this->addSql('ALTER TABLE ligne_commande CHANGE commande_id commande_id INT DEFAULT NULL, CHANGE product_id product_id INT DEFAULT NULL');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE product_jour_distrib (product_id INT NOT NULL, jour_distrib_id INT NOT NULL, INDEX IDX_FFD0B16264949231 (jour_distrib_id), INDEX IDX_FFD0B1624584665A (product_id), PRIMARY KEY(product_id, jour_distrib_id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE product_jour_distrib ADD CONSTRAINT FK_FFD0B1624584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_jour_distrib ADD CONSTRAINT FK_FFD0B16264949231 FOREIGN KEY (jour_distrib_id) REFERENCES jour_distrib (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE jour_distrib_product');
        $this->addSql('ALTER TABLE commande CHANGE jour_distrib_id jour_distrib_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE jour_distrib CHANGE poid_restant poid_restant DOUBLE PRECISION DEFAULT \'NULL\', CHANGE closed closed TINYINT(1) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE ligne_commande CHANGE commande_id commande_id INT DEFAULT NULL, CHANGE product_id product_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product CHANGE prix_final prix_final DOUBLE PRECISION DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE user CHANGE roles roles LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_bin`');
    }
}
