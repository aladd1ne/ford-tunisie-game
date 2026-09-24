<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Participant;
use App\Entity\Spin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Remet « La Roue Ford » à zéro entre deux opérations.
 *
 * Supprime tous les participants et leurs tirages, puis purge les sessions
 * PHP (back-office compris : tout le monde est déconnecté). Les lots et les
 * comptes utilisateurs sont conservés ; les stocks ne sont pas restaurés.
 */
#[AsCommand(
    name: 'app:game:reset',
    description: 'Supprime toutes les inscriptions et vide les sessions.',
)]
class ResetRegistrationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%session.save_path%')]
        private readonly ?string $sessionSavePath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Ne pas demander de confirmation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force')
            && !$io->confirm('Supprimer définitivement toutes les inscriptions et tous les tirages, et vider les sessions ?', false)
        ) {
            $io->note('Opération annulée.');

            return Command::SUCCESS;
        }

        [$spins, $participants] = $this->entityManager->wrapInTransaction(fn (EntityManagerInterface $em): array => [
            $em->createQuery(sprintf('DELETE FROM %s', Spin::class))->execute(),
            $em->createQuery(sprintf('DELETE FROM %s', Participant::class))->execute(),
        ]);

        $sessions = $this->clearSessions();

        $io->success(sprintf(
            '%d participant(s) et %d tirage(s) supprimé(s), %d session(s) vidée(s).',
            $participants,
            $spins,
            $sessions,
        ));

        return Command::SUCCESS;
    }

    private function clearSessions(): int
    {
        if (null === $this->sessionSavePath || !is_dir($this->sessionSavePath)) {
            return 0;
        }

        $files = iterator_to_array(
            (new Finder())->files()->in($this->sessionSavePath)->name('sess_*')->ignoreDotFiles(false),
            false,
        );

        (new Filesystem())->remove($files);

        return \count($files);
    }
}
