<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma initial de « La Roue Ford » : participants, lots, tirages et
 * utilisateurs du back-office.
 *
 * À noter : l'index unique sur spin.participant_id est ce qui garantit, au
 * niveau de la base, qu'un participant ne peut enregistrer qu'un seul tirage.
 */
final class Version20260919104015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création des tables participant, prize, spin et user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE participant (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, company VARCHAR(160) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(40) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, uuid VARCHAR(255) DEFAULT NULL, INDEX participant_email_idx (email), UNIQUE INDEX participant_uuid_uq (uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE prize (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(20) NOT NULL, weight INT UNSIGNED DEFAULT 0 NOT NULL, remaining_stock INT UNSIGNED DEFAULT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, display_order INT DEFAULT 0 NOT NULL, color VARCHAR(7) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, uuid VARCHAR(255) DEFAULT NULL, INDEX prize_selectable_idx (is_active, remaining_stock), UNIQUE INDEX prize_uuid_uq (uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE spin (id INT AUTO_INCREMENT NOT NULL, participant_id INT NOT NULL, prize_id INT NOT NULL, prize_name VARCHAR(120) NOT NULL, prize_type VARCHAR(20) NOT NULL, spun_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, uuid VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_120A248F9D1C3019 (participant_id), INDEX IDX_120A248FBBE43214 (prize_id), INDEX spin_spun_at_idx (spun_at), UNIQUE INDEX spin_uuid_uq (uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, uuid VARCHAR(255) DEFAULT NULL, UNIQUE INDEX user_email_uq (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE spin ADD CONSTRAINT FK_120A248F9D1C3019 FOREIGN KEY (participant_id) REFERENCES participant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE spin ADD CONSTRAINT FK_120A248FBBE43214 FOREIGN KEY (prize_id) REFERENCES prize (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spin DROP FOREIGN KEY FK_120A248F9D1C3019');
        $this->addSql('ALTER TABLE spin DROP FOREIGN KEY FK_120A248FBBE43214');
        $this->addSql('DROP TABLE participant');
        $this->addSql('DROP TABLE prize');
        $this->addSql('DROP TABLE spin');
        $this->addSql('DROP TABLE `user`');
    }
}
