<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Groupes de destinataires des newsletters.
 */
final class Version20261008090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Groupes de newsletter : membres et groupes ciblés par chaque newsletter';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE newsletter_group (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, UNIQUE INDEX UNIQ_E7AD08315E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE newsletter_group_user (newsletter_group_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_49DCB49323561F2D (newsletter_group_id), INDEX IDX_49DCB493A76ED395 (user_id), PRIMARY KEY (newsletter_group_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE newsletter_target_group (newsletter_id INT NOT NULL, newsletter_group_id INT NOT NULL, INDEX IDX_DA86EEB722DB1917 (newsletter_id), INDEX IDX_DA86EEB723561F2D (newsletter_group_id), PRIMARY KEY (newsletter_id, newsletter_group_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE newsletter_group_user ADD CONSTRAINT FK_49DCB49323561F2D FOREIGN KEY (newsletter_group_id) REFERENCES newsletter_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_group_user ADD CONSTRAINT FK_49DCB493A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_target_group ADD CONSTRAINT FK_DA86EEB722DB1917 FOREIGN KEY (newsletter_id) REFERENCES newsletter (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_target_group ADD CONSTRAINT FK_DA86EEB723561F2D FOREIGN KEY (newsletter_group_id) REFERENCES newsletter_group (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE newsletter_group_user DROP FOREIGN KEY FK_49DCB49323561F2D');
        $this->addSql('ALTER TABLE newsletter_group_user DROP FOREIGN KEY FK_49DCB493A76ED395');
        $this->addSql('ALTER TABLE newsletter_target_group DROP FOREIGN KEY FK_DA86EEB722DB1917');
        $this->addSql('ALTER TABLE newsletter_target_group DROP FOREIGN KEY FK_DA86EEB723561F2D');
        $this->addSql('DROP TABLE newsletter_group_user');
        $this->addSql('DROP TABLE newsletter_target_group');
        $this->addSql('DROP TABLE newsletter_group');
    }
}
