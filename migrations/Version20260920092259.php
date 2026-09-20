<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Supprimer un lot ne doit plus être bloqué par ses tirages passés : le nom
 * et le type du lot sont déjà recopiés sur chaque Spin (voir l'entité), donc
 * la suppression peut désormais mettre spin.prize_id à NULL sans perdre
 * l'historique affiché au participant.
 */
final class Version20260920092259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace ON DELETE RESTRICT par ON DELETE SET NULL sur spin.prize_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spin DROP FOREIGN KEY FK_120A248FBBE43214');
        $this->addSql('ALTER TABLE spin ADD CONSTRAINT FK_120A248FBBE43214 FOREIGN KEY (prize_id) REFERENCES prize (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spin DROP FOREIGN KEY FK_120A248FBBE43214');
        $this->addSql('ALTER TABLE spin ADD CONSTRAINT FK_120A248FBBE43214 FOREIGN KEY (prize_id) REFERENCES prize (id) ON DELETE RESTRICT');
    }
}
