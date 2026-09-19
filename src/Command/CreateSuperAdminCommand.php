<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Crée (ou met à jour) le compte super administrateur du back-office.
 *
 * ROLE_SUPER_ADMIN implique ROLE_ADMIN (voir security.yaml > role_hierarchy),
 * donc ce compte accède à tout /admin. La commande est idempotente : la
 * relancer avec le même e-mail met simplement à jour le mot de passe.
 */
#[AsCommand(
    name: 'app:admin:create-super-admin',
    description: 'Crée ou met à jour le compte super administrateur du back-office de La Roue Ford.',
)]
class CreateSuperAdminCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Adresse e-mail du super administrateur')
            ->addArgument('password', InputArgument::OPTIONAL, 'Mot de passe (au moins '.self::MIN_PASSWORD_LENGTH.' caractères)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Compte super administrateur — La Roue Ford');

        $email = $input->getArgument('email') ?? $io->ask('Adresse e-mail');
        $emailViolations = $this->validator->validate((string) $email, [new Email()]);

        if (0 !== \count($emailViolations)) {
            $io->error(sprintf("L'adresse e-mail « %s » n'est pas valide.", $email));

            return Command::FAILURE;
        }

        $password = $input->getArgument('password') ?? $this->askPassword($io);

        if (\mb_strlen((string) $password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_PASSWORD_LENGTH));

            return Command::FAILURE;
        }

        $email = mb_strtolower(trim((string) $email));
        $user = $this->userRepository->findOneByEmail($email);
        $isNew = null === $user;
        $user ??= new User($email);

        $user->setEmail($email);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $password));

        $this->userRepository->save($user);

        $io->success(sprintf(
            $isNew ? 'Compte super administrateur « %s » créé.' : 'Compte super administrateur « %s » mis à jour.',
            $email,
        ));

        return Command::SUCCESS;
    }

    private function askPassword(SymfonyStyle $io): string
    {
        $question = new Question('Mot de passe');
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        return (string) $io->askQuestion($question);
    }
}
