<?php

namespace Application\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
class Version20161205102209 extends AbstractMigration
{
    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE payments_revert_logs (id INT AUTO_INCREMENT NOT NULL, payment_log_id INT DEFAULT NULL, moderator_id INT DEFAULT NULL, paid TINYINT(1) NOT NULL, info LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_70F0B90FCF5FC610 (payment_log_id), INDEX IDX_70F0B90FD0AFA354 (moderator_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE payments_revert_logs ADD CONSTRAINT FK_70F0B90FCF5FC610 FOREIGN KEY (payment_log_id) REFERENCES payments_logs (id)');
        $this->addSql('ALTER TABLE payments_revert_logs ADD CONSTRAINT FK_70F0B90FD0AFA354 FOREIGN KEY (moderator_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE payments_logs ADD int_ref VARCHAR(255) DEFAULT NULL');
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP TABLE payments_revert_logs');
        $this->addSql('ALTER TABLE payments_logs DROP int_ref');
    }
}
