<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La roue affiche désormais autant de cases « perdu » que de lots (50/50) :
 * un tirage peut ne désigner aucun lot, donc spin.prize_id/prize_name/
 * prize_type doivent pouvoir rester vides.
 */
final class Version20260920090103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rend spin.prize_id, prize_name et prize_type nullables (tirage perdant).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spin CHANGE prize_id prize_id INT DEFAULT NULL, CHANGE prize_name prize_name VARCHAR(120) DEFAULT NULL, CHANGE prize_type prize_type VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spin CHANGE prize_id prize_id INT NOT NULL, CHANGE prize_name prize_name VARCHAR(120) NOT NULL, CHANGE prize_type prize_type VARCHAR(20) NOT NULL');
    }
}
