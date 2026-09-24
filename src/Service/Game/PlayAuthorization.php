<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Participant;
use App\Exception\Game\AlreadyPlayedException;
use App\Repository\ParticipantRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Accès à la roue.
 *
 * L'inscription ne suffit pas pour jouer : à l'entrée, l'équipe vérifie dans
 * le back-office que le visiteur est inscrit puis l'autorise à jouer. La roue
 * (écran public) accueille alors le dernier participant autorisé qui n'a pas
 * encore joué.
 */
final class PlayAuthorization
{
    public function __construct(
        private readonly ParticipantRepository $participantRepository,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AlreadyPlayedException
     */
    public function authorize(Participant $participant): void
    {
        if ($participant->hasPlayed()) {
            throw new AlreadyPlayedException();
        }

        $participant->authorizePlay(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->participantRepository->save($participant);

        $this->logger->info('Participant {uuid} autorisé à jouer.', [
            'uuid' => (string) $participant->getUuid(),
        ]);
    }

    public function currentPlayer(): ?Participant
    {
        return $this->participantRepository->findCurrentPlayer();
    }
}
