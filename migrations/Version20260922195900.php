<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un participant peut désormais retenter sa chance après une case « perdu »
 * (« Rejouer ») : l'index unique sur spin.participant_id, qui limitait un
 * participant à un seul tirage, est remplacé par un index simple (toujours
 * nécessaire pour la clé étrangère). C'est désormais SpinService, et non
 * plus la base, qui garantit qu'aucune tentative n'est acceptée après un
 * gain.
 */
final class Version20260922195900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace l\'index unique de spin.participant_id par un index simple : un participant peut rejouer après une case « perdu ».';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spin DROP INDEX UNIQ_120A248F9D1C3019, ADD INDEX IDX_120A248F9D1C3019 (participant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spin DROP INDEX IDX_120A248F9D1C3019, ADD UNIQUE INDEX UNIQ_120A248F9D1C3019 (participant_id)');
    }
}
