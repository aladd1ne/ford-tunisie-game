<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Participant;
use App\Entity\Prize;
use App\Entity\Spin;
use App\Exception\Game\NoPrizeAvailableException;
use App\Repository\PrizeRepository;
use App\Repository\SpinRepository;
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
 *  - un participant peut retenter sa chance après une case « perdu », mais
 *    plus aucune tentative n'est acceptée dès qu'il a gagné (verrou
 *    pessimiste sur la ligne du participant + relecture en base du tirage
 *    gagnant sous ce verrou) ;
 *  - un lot en rupture de stock n'est jamais attribué, même si plusieurs
 *    joueurs tirent la dernière unité au même instant (UPDATE conditionnel) ;
 *  - tirage et décrément de stock sont dans la même transaction : en cas
 *    d'échec, aucun stock n'est consommé ;
 *  - à chaque tentative, un participant a 70 % de chances de gagner un lot
 *    et 30 % de ne rien gagner, indépendamment du poids relatif des lots
 *    entre eux.
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
        private readonly RandomNumberGeneratorInterface $randomNumberGenerator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fait tourner la roue pour un participant.
     *
     * Chaque case « perdu » peut être suivie d'une nouvelle tentative : ce
     * n'est que lorsqu'un tirage gagnant existe déjà que l'opération devient
     * idempotente et renvoie ce tirage tel quel, sans jamais en créer un
     * second (double-clic, rejeu réseau ou tentative après un gain).
     */
    public function spin(Participant $participant, SpinContext $context = new SpinContext()): Spin
    {
        $winningSpin = $this->spinRepository->findWinningByParticipant($participant);

        if (null !== $winningSpin) {
            return $winningSpin;
        }

        return $this->entityManager->wrapInTransaction(
            fn (): Spin => $this->doSpin($participant, $context),
        );
    }

    private function doSpin(Participant $participant, SpinContext $context): Spin
    {
        // Sérialise les tentatives concurrentes d'un même participant : la
        // seconde requête attend la fin de la première avant de relire l'état
        // réel (a-t-il gagné entre-temps ?) sous ce même verrou.
        $this->entityManager->lock($participant, LockMode::PESSIMISTIC_WRITE);

        $winningSpin = $this->spinRepository->findWinningByParticipant($participant);

        if (null !== $winningSpin) {
            return $winningSpin;
        }

        $prize = $this->resolveOutcome();

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
            'prize' => $spin->getPrizeName() ?? 'perdu',
            'uuid' => (string) $participant->getUuid(),
        ]);

        return $spin;
    }

    /**
     * Tire à pile ou face si le participant gagne un lot, avant même de
     * savoir lequel : 70 % de chances de gagner, 30 % de ne rien gagner.
     *
     * Si le tirage gagnant ne trouve plus aucun lot disponible (dotation
     * épuisée en cours d'opération), le tour est traité comme une case
     * « perdu » plutôt que comme une erreur bloquante : la roue continue de
     * tourner normalement une fois les lots physiques distribués.
     */
    private function resolveOutcome(): ?Prize
    {
        // Tickets 1 à 7 = case lot (70 %), tickets 8 à 10 = case perdu (30 %).
        if ($this->randomNumberGenerator->nextInt(1, 10) > 7) {
            return null;
        }

        try {
            return $this->reservePrize();
        } catch (NoPrizeAvailableException) {
            return null;
        }
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
