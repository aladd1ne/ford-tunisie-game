<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'inscription et la roue sont désormais séparées : un participant inscrit
 * ne peut jouer qu'après avoir été autorisé par l'équipe depuis le
 * back-office (participant.play_authorized_at). Les inscriptions existantes
 * restent non autorisées.
 *
 * Aucun index unique n'est ajouté sur spin.participant_id : l'historique
 * peut déjà contenir plusieurs tentatives pour un même participant. C'est
 * SpinService qui garantit désormais un seul tirage par participant.
 */
final class Version20260924090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute participant.play_authorized_at : autorisation de jouer donnée depuis le back-office.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participant ADD play_authorized_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX participant_play_authorized_at_idx ON participant (play_authorized_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX participant_play_authorized_at_idx ON participant');
        $this->addSql('ALTER TABLE participant DROP play_authorized_at');
    }
}
