<?php

namespace Application\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
class Version20160525161430 extends AbstractMigration
{
    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE promo_key ADD created DATETIME NOT NULL');
        $this->addSql('ALTER TABLE promo_campaigns ADD created DATETIME NOT NULL');
        $this->addSql('UPDATE promo_campaigns SET created=used_from');
        $this->addSql('UPDATE promo_key p LEFT JOIN promo_campaigns pc ON (pc.id=p.campaign_id) SET p.created=pc.created');
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE promo_campaigns DROP created');
        $this->addSql('ALTER TABLE promo_key DROP created');
    }
}
