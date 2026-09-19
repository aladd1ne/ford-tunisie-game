<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Participant;
use App\Entity\Prize;
use App\Entity\Spin;
use App\Exception\Game\AlreadySpunException;
use App\Exception\Game\NoPrizeAvailableException;
use App\Repository\PrizeRepository;
use App\Repository\SpinRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Logique de jeu de « La Roue Ford ».
 *
 * C'est le seul point d'entrée autorisé pour déterminer un résultat. Le
 * service est volontairement ignorant du protocole HTTP : les contrôleurs se
 * contentent de lui transmettre un participant et un contexte de traçabilité.
 *
 * Garanties apportées :
 *  - un participant ne joue qu'une seule fois (verrou pessimiste sur la ligne
 *    du participant + index unique sur spin.participant_id) ;
 *  - un lot en rupture de stock n'est jamais attribué, même si plusieurs
 *    joueurs tirent la dernière unité au même instant (UPDATE conditionnel) ;
 *  - tirage et décrément de stock sont dans la même transaction : en cas
 *    d'échec, aucun stock n'est consommé.
 */
final class SpinService
{
    /**
     * Garde-fou : nombre maximal de nouveaux tirages lorsque le lot désigné
     * vient d'être épuisé par un autre joueur. Borné par le nombre de lots.
     */
    private const MAX_SELECTION_ATTEMPTS = 25;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrizeRepository $prizeRepository,
        private readonly SpinRepository $spinRepository,
        private readonly PrizeSelectorInterface $prizeSelector,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fait tourner la roue pour un participant.
     *
     * L'opération est idempotente : si le participant a déjà joué, son tirage
     * existant est renvoyé tel quel. Un double-clic, un rejeu réseau ou un
     * rafraîchissement ne produisent donc jamais un second résultat.
     *
     * @throws NoPrizeAvailableException
     * @throws AlreadySpunException
     */
    public function spin(Participant $participant, SpinContext $context = new SpinContext()): Spin
    {
        $existingSpin = $this->spinRepository->findOneByParticipant($participant);

        if (null !== $existingSpin) {
            return $existingSpin;
        }

        try {
            return $this->entityManager->wrapInTransaction(
                fn (): Spin => $this->doSpin($participant, $context),
            );
        } catch (UniqueConstraintViolationException $exception) {
            // Deux tirages réellement simultanés : la base a tranché.
            $this->logger->notice('Tirage concurrent rejeté pour le participant {uuid}.', [
                'uuid' => (string) $participant->getUuid(),
            ]);

            throw new AlreadySpunException($exception);
        }
    }

    /**
     * @throws NoPrizeAvailableException
     */
    private function doSpin(Participant $participant, SpinContext $context): Spin
    {
        // Sérialise les tirages concurrents d'un même participant : la seconde
        // requête attend la fin de la première, puis retrouve son tirage.
        $this->entityManager->lock($participant, LockMode::PESSIMISTIC_WRITE);

        $existingSpin = $this->spinRepository->findOneByParticipant($participant);

        if (null !== $existingSpin) {
            return $existingSpin;
        }

        $prize = $this->reservePrize();

        $spin = new Spin(
            $participant,
            $prize,
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $context->ipAddress,
            $context->userAgent,
        );

        $this->entityManager->persist($spin);
        $this->entityManager->flush();

        $this->logger->info('Tirage enregistré : {prize} pour le participant {uuid}.', [
            'prize' => $spin->getPrizeName(),
            'uuid' => (string) $participant->getUuid(),
        ]);

        return $spin;
    }

    /**
     * Tire un lot au sort et réserve immédiatement une unité de son stock.
     *
     * Si le lot désigné vient d'être épuisé par un autre joueur, il est écarté
     * et un nouveau tirage est effectué sur les lots restants.
     *
     * @throws NoPrizeAvailableException
     */
    private function reservePrize(): Prize
    {
        $candidates = $this->prizeRepository->findSelectable();

        for ($attempt = 0; $attempt < self::MAX_SELECTION_ATTEMPTS; ++$attempt) {
            if ([] === $candidates) {
                throw new NoPrizeAvailableException();
            }

            $prize = $this->prizeSelector->select($candidates);

            if ($this->prizeRepository->decrementStock($prize)) {
                return $prize;
            }

            $this->logger->notice('Lot « {prize} » épuisé pendant le tirage, nouveau tirage.', [
                'prize' => $prize->getName(),
            ]);

            $candidates = array_values(array_filter(
                $candidates,
                static fn (Prize $candidate): bool => $candidate !== $prize,
            ));
        }

        throw new NoPrizeAvailableException();
    }
}
