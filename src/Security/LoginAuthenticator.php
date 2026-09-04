<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\AuthSource;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Le formulaire de connexion unique, adossé à deux sources.
 *
 * Un seul écran plutôt qu'un choix imposé à l'utilisateur : personne ne devrait
 * avoir à savoir si son compte vit dans l'annuaire ou en base. C'est
 * l'identifiant saisi qui détermine le mode de vérification.
 *
 * Un compte local est vérifié par le hachage stocké ; un compte d'annuaire par
 * un bind, l'application ne détenant alors aucun secret le concernant. Un
 * compte SSO refuse le mot de passe : accepter une seconde voie d'entrée
 * viderait de son sens la délégation d'authentification.
 */
final class LoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    private bool $directoryVerified = false;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly UserRepository $users,
        private readonly LdapDirectory $directory,
        private readonly ExternalUserProvisioner $provisioner,
        private readonly UserPasswordVerifier $passwords,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $username = trim((string) $request->request->get('username', ''));
        $password = (string) $request->request->get('password', '');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $username);
        $this->directoryVerified = false;

        return new Passport(
            new UserBadge($username, fn (string $identifier): UserInterface => $this->load($identifier, $password)),
            new CustomCredentials(
                fn (string $credentials, UserInterface $user): bool => $this->verify($user, $credentials),
                $password,
            ),
            [new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token'))],
        );
    }

    /**
     * Trouve l'utilisateur, en interrogeant l'annuaire si nécessaire.
     *
     * Le bind LDAP a lieu ici et non à l'étape des identifiants : un compte
     * d'annuaire inconnu de la base doit pouvoir se créer à la première
     * connexion, ce qui suppose de l'avoir authentifié avant de savoir qui il est.
     */
    private function load(string $identifier, string $password): UserInterface
    {
        if ('' === $identifier) {
            throw new UserNotFoundException();
        }

        $existing = $this->users->findOneBy(['username' => $identifier]);

        if (null !== $existing && AuthSource::Oidc === $existing->getAuthSource()) {
            throw new CustomUserMessageAuthenticationException(
                'Ce compte se connecte par le fournisseur d\'identité.',
            );
        }

        if (null !== $existing && AuthSource::Local === $existing->getAuthSource()) {
            return $existing;
        }

        $identity = $this->directory->authenticate($identifier, $password);
        if (null === $identity) {
            // Message volontairement identique à celui d'un mot de passe faux :
            // distinguer les deux révélerait quels comptes existent.
            throw new UserNotFoundException();
        }

        $this->directoryVerified = true;

        return $this->provisioner->provision(
            AuthSource::Ldap,
            $identity->username,
            $identity->dn,
            $identity->displayName,
            $identity->email,
            $identity->groups,
        );
    }

    private function verify(UserInterface $user, string $password): bool
    {
        if ($user instanceof User && AuthSource::Local === $user->getAuthSource()) {
            return $this->passwords->isValid($user, $password);
        }

        // Le bind a déjà tranché : l'application ne rejoue pas la vérification.
        return $this->directoryVerified;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $target = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($target ?? $this->urls->generate('app_dashboard'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate('app_login');
    }
}
