<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Enum\AuditAction;
use App\Service\AuditRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Journalise les entrées et les sorties.
 *
 * Un journal d'audit qui ne dit pas qui s'est connecté, et surtout qui a
 * essayé sans y parvenir, ne répond pas à la question qu'on lui posera le jour
 * où elle se posera. Les échecs sont donc tracés au même titre que les succès,
 * avec l'identifiant tenté et l'adresse d'origine.
 */
final class AuthenticationAuditListener
{
    public function __construct(
        private readonly AuditRecorder $recorder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if ($user instanceof User) {
            $user->setLastLoginAt(new \DateTimeImmutable());
            $this->em->persist($user);
        }

        $this->write(
            AuditAction::Login,
            $user->getUserIdentifier(),
            \sprintf('Connexion (%s)', $user instanceof User ? $user->getAuthSource()->label() : 'inconnue'),
        );
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onFailure(LoginFailureEvent $event): void
    {
        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        $attempted = $badge instanceof UserBadge ? $badge->getUserIdentifier() : 'inconnu';

        $this->write(
            AuditAction::LoginFailed,
            $attempted,
            // Le motif technique n'est pas repris : il distinguerait un compte
            // absent d'un mot de passe faux, ce que la page de connexion se
            // garde justement de révéler.
            'Échec de connexion',
        );
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if (null === $user) {
            return;
        }

        $this->write(AuditAction::Logout, $user->getUserIdentifier(), 'Déconnexion');
    }

    private function write(AuditAction $action, string $actor, string $label): void
    {
        $this->em->persist(
            $this->recorder->build($action, null, null, $label, $actor),
        );
        $this->em->flush();
    }
}
