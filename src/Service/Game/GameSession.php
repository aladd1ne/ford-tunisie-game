<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Participant;
use App\Repository\ParticipantRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Suit le participant inscrit tout au long de la session de jeu.
 *
 * Seul l'identifiant du participant est conservé en session (côté serveur) ;
 * l'entité est systématiquement rechargée depuis la base. Le client ne peut
 * donc ni désigner un autre participant, ni se déclarer inscrit.
 */
final class GameSession
{
    private const PARTICIPANT_KEY = 'roue_ford.participant_uuid';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ParticipantRepository $participantRepository,
    ) {
    }

    public function start(Participant $participant): void
    {
        $this->requestStack->getSession()->set(self::PARTICIPANT_KEY, (string) $participant->getUuid());
    }

    public function getParticipant(): ?Participant
    {
        $session = $this->requestStack->getSession();
        $uuid = $session->get(self::PARTICIPANT_KEY);

        if (!is_string($uuid) || '' === $uuid) {
            return null;
        }

        $participant = $this->participantRepository->findOneByUuid($uuid);

        if (null === $participant) {
            // Inscription supprimée entre-temps : on repart de zéro.
            $session->remove(self::PARTICIPANT_KEY);
        }

        return $participant;
    }

    public function isRegistered(): bool
    {
        return null !== $this->getParticipant();
    }

    /**
     * Termine la partie en cours. Le tirage déjà enregistré reste en base :
     * « Nouvelle partie » repart d'une inscription vierge et ne peut donc pas
     * rejouer ni modifier le résultat précédent.
     */
    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::PARTICIPANT_KEY);
    }
}
