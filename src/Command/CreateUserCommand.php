<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\AuthSource;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cree ou met a jour un compte local.
 *
 * C'est le compte de secours du dispositif : quand l'annuaire est injoignable
 * ou que le SSO refuse, il reste une porte d'entree. Il doit donc pouvoir etre
 * cree sans interface, depuis la machine.
 */
#[AsCommand(name: 'app:user:create', description: 'Crée ou met à jour un compte local')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Identifiant de connexion')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Mot de passe (demandé si absent)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Donne le rôle ROLE_ADMIN')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse de courriel')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom affiché');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');

        $password = $input->getOption('password');
        if (null === $password) {
            $question = (new Question('Mot de passe : '))->setHidden(true)->setHiddenFallback(false);
            $password = $io->askQuestion($question);
        }

        if (!\is_string($password) || \strlen($password) < 12) {
            $io->error('Le mot de passe doit faire au moins 12 caractères.');

            return Command::FAILURE;
        }

        $user = $this->users->findOneBy(['username' => $username]);
        $existed = null !== $user;
        $user ??= new User();

        $user->setUsername($username)
            ->setAuthSource(AuthSource::Local)
            ->setActive(true)
            ->setEmail($input->getOption('email') ?? $user->getEmail())
            ->setDisplayName($input->getOption('name') ?? $user->getDisplayName() ?? $username)
            ->setRoles($input->getOption('admin') ? [User::ROLE_ADMIN] : $user->getRoles());

        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf(
            '%s le compte local « %s » (%s).',
            $existed ? 'Mis à jour' : 'Créé',
            $username,
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
