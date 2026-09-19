<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Prize;
use App\Enum\PrizeType;
use App\Repository\PrizeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée la dotation par défaut de « La Roue Ford ».
 *
 * Les lots vivent en base : cette commande ne sert qu'à initialiser une
 * opération. Poids, stocks et activation restent modifiables ensuite sans
 * toucher au code du jeu.
 */
#[AsCommand(
    name: 'app:game:seed-prizes',
    description: 'Initialise les lots par défaut de La Roue Ford.',
)]
class SeedPrizesCommand extends Command
{
    /**
     * @var list<array{name: string, description: string|null, type: PrizeType, weight: int, stock: int|null, color: string}>
     */
    private const DEFAULT_PRIZES = [
        [
            'name' => 'Un week-end au volant d’une Ford Puma',
            'description' => 'Deux jours d’essai, carburant et assurance inclus.',
            'type' => PrizeType::MAIN,
            'weight' => 3,
            'stock' => 2,
            'color' => '#00095B',
        ],
        [
            'name' => 'Un pack entretien Ford offert',
            'description' => 'Révision complète dans le réseau Ford partenaire.',
            'type' => PrizeType::MAIN,
            'weight' => 7,
            'stock' => 10,
            'color' => '#066FEF',
        ],
        [
            'name' => 'Une enceinte connectée Ford',
            'description' => 'Édition limitée aux couleurs de la marque.',
            'type' => PrizeType::CONSOLATION,
            'weight' => 15,
            'stock' => 25,
            'color' => '#1B2A4A',
        ],
        [
            'name' => 'Une casquette Ford',
            'description' => null,
            'type' => PrizeType::CONSOLATION,
            'weight' => 25,
            'stock' => 100,
            'color' => '#4D7DF2',
        ],
        [
            'name' => 'Un mug Ford',
            'description' => null,
            'type' => PrizeType::CONSOLATION,
            'weight' => 25,
            'stock' => 100,
            'color' => '#8FB4F7',
        ],
        [
            'name' => 'Un porte-clés Ford',
            'description' => null,
            'type' => PrizeType::CONSOLATION,
            'weight' => 25,
            'stock' => null,
            'color' => '#C9DCFB',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrizeRepository $prizeRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;

        foreach (self::DEFAULT_PRIZES as $position => $definition) {
            if (null !== $this->prizeRepository->findOneBy(['name' => $definition['name']])) {
                continue;
            }

            $prize = (new Prize($definition['name'], $definition['type'], $definition['weight']))
                ->setDescription($definition['description'])
                ->setRemainingStock($definition['stock'])
                ->setDisplayOrder($position)
                ->setColor($definition['color'])
                ->setActive(true);

            $this->entityManager->persist($prize);
            ++$created;
        }

        $this->entityManager->flush();

        $io->success(sprintf('%d lot(s) créé(s). La commande est idempotente.', $created));

        $rows = array_map(
            static fn (Prize $prize): array => [
                $prize->getDisplayOrder(),
                $prize->getName(),
                $prize->getType()->label(),
                $prize->getWeight(),
                $prize->hasLimitedStock() ? (string) $prize->getRemainingStock() : 'illimité',
            ],
            $this->prizeRepository->findForWheel(),
        );

        $io->table(['Ordre', 'Lot', 'Type', 'Poids', 'Stock'], $rows);

        return Command::SUCCESS;
    }
}
