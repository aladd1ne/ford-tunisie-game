<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\RegistrationDto;
use App\Entity\Participant;
use App\Repository\ParticipantRepository;
use Psr\Log\LoggerInterface;

/**
 * Transforme une inscription validée en participant persisté.
 */
final class ParticipantRegistrar
{
    public function __construct(
        private readonly ParticipantRepository $participantRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(RegistrationDto $registration): Participant
    {
        $participant = $registration->toParticipant();

        $this->participantRepository->save($participant);

        $this->logger->info('Nouvelle inscription à La Roue Ford : {uuid}.', [
            'uuid' => (string) $participant->getUuid(),
        ]);

        return $participant;
    }
}
