<?php

namespace Application\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
class Version20160421170558 extends AbstractMigration
{
    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE category_prices ADD base INT NOT NULL, ADD teor INT NOT NULL, ADD offline_1 INT NOT NULL, ADD offline_2 INT NOT NULL, ADD online_onetime INT NOT NULL, ADD online_1 INT NOT NULL, ADD online_2 INT NOT NULL, DROP price_edu, DROP price_drv, DROP price_drv_at');
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE category_prices ADD price_edu INT NOT NULL, ADD price_drv INT NOT NULL, ADD price_drv_at INT NOT NULL, DROP base, DROP teor, DROP offline_1, DROP offline_2, DROP online_onetime, DROP online_1, DROP online_2');
    }
}
