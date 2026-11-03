<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paiement en ligne HelloAsso.
 */
final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paiement définitif des commandes (chèque ou HelloAsso) et demande de paiement';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE commande ADD helloasso_checkout_id INT DEFAULT NULL, ADD helloasso_order_id INT DEFAULT NULL, ADD payee TINYINT DEFAULT 0 NOT NULL, ADD paiement_demande_le DATETIME DEFAULT NULL');
        // Commandes déjà livrées : considérées comme réglées
        $this->addSql('UPDATE commande SET payee = 1 WHERE livree = 1');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE commande DROP helloasso_checkout_id, DROP helloasso_order_id, DROP payee, DROP paiement_demande_le');
    }
}
