<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Participant;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exporte la liste des inscriptions au format Excel (.xlsx).
 *
 * Les colonnes reprennent celles de la liste du back-office, pour que le
 * fichier téléchargé corresponde à ce que l'équipe voit à l'écran.
 */
final class ParticipantSpreadsheetExporter
{
    private const HEADERS = [
        'ID',
        'Prénom',
        'Nom',
        'Société ou agence',
        'Adresse e-mail',
        'Téléphone',
        'A joué',
        'Cadeau obtenu',
        'Inscrit le',
        'Autorisé à jouer le',
    ];

    private const COLUMN_WIDTHS = [8, 18, 18, 28, 32, 18, 10, 28, 18, 20];

    private const DATE_FORMAT = 'dd/mm/yyyy hh:mm';

    /**
     * Écrit les participants dans un fichier temporaire et renvoie son chemin ;
     * l'appelant est chargé de le supprimer après envoi.
     *
     * @param iterable<Participant> $participants
     */
    public function export(iterable $participants): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inscriptions_');

        if (false === $path) {
            throw new \RuntimeException('Impossible de créer le fichier d’export.');
        }

        $options = new Options();
        foreach (self::COLUMN_WIDTHS as $index => $width) {
            $options->setColumnWidth($width, $index + 1);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Inscriptions');

        $writer->addRow(Row::fromValues(self::HEADERS, (new Style())->setFontBold()->setBackgroundColor('D9E2F3')));

        $dateStyle = (new Style())->setFormat(self::DATE_FORMAT);
        $dateColumns = [8 => $dateStyle, 9 => $dateStyle];

        foreach ($participants as $participant) {
            $writer->addRow(Row::fromValuesWithStyles([
                $participant->getId(),
                $participant->getFirstName(),
                $participant->getLastName(),
                $participant->getCompany(),
                $participant->getEmail(),
                $participant->getPhone() ?? '',
                $participant->hasPlayed() ? 'Oui' : 'Non',
                $participant->getWonPrizeName() ?? '',
                $participant->getCreatedAt(),
                $participant->getPlayAuthorizedAt(),
            ], null, $dateColumns));
        }

        $writer->close();

        return $path;
    }
}
