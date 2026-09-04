<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\AuthSource;
use App\Security\ExternalUserProvisioner;
use App\Security\OidcClient;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Connexion déléguée à un fournisseur d'identité.
 *
 * Le paramètre « state » est vérifié au retour et consommé aussitôt : c'est ce
 * qui empêche un tiers de faire aboutir chez nous une autorisation qu'il aurait
 * lui-même déclenchée.
 */
final class OidcController extends AbstractController
{
    public function __construct(
        private readonly OidcClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/connexion/sso', name: 'app_oidc_start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        if (!$this->client->isEnabled()) {
            throw $this->createNotFoundException('Aucun fournisseur d\'identité n\'est configuré.');
        }

        $provider = $this->client->provider($this->callbackUrl());
        $url = $provider->getAuthorizationUrl();

        $request->getSession()->set('oidc_state', $provider->getState());

        return $this->redirect($url);
    }

    #[Route('/connexion/sso/retour', name: 'app_oidc_callback', methods: ['GET'])]
    public function callback(
        Request $request,
        Security $security,
        ExternalUserProvisioner $provisioner,
    ): Response {
        if (!$this->client->isEnabled()) {
            throw $this->createNotFoundException('Aucun fournisseur d\'identité n\'est configuré.');
        }

        $session = $request->getSession();
        $expected = $session->get('oidc_state');
        $session->remove('oidc_state');

        if (null !== $request->query->get('error')) {
            return $this->refuse($request->query->get('error_description') ?? 'Le fournisseur d\'identité a refusé la demande.');
        }

        $state = $request->query->get('state');
        if (null === $expected || $state !== $expected) {
            // État absent ou différent : la réponse ne correspond à aucune
            // demande partie d'ici.
            return $this->refuse('Réponse d\'authentification inattendue. Recommencez la connexion.');
        }

        $code = $request->query->get('code');
        if (!\is_string($code) || '' === $code) {
            return $this->refuse('Le fournisseur d\'identité n\'a renvoyé aucun code.');
        }

        try {
            $provider = $this->client->provider($this->callbackUrl());
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $claims = $provider->getResourceOwner($token)->toArray();
        } catch (IdentityProviderException $e) {
            $this->logger->warning('Échange OIDC refusé.', ['exception' => $e]);

            return $this->refuse('Le fournisseur d\'identité a refusé l\'échange.');
        } catch (\Throwable $e) {
            $this->logger->error('Fournisseur d\'identité injoignable.', ['exception' => $e]);

            return $this->refuse('Fournisseur d\'identité injoignable.');
        }

        $username = $this->client->usernameFrom($claims);
        $subject = $claims['sub'] ?? null;

        if (null === $username || !\is_string($subject) || '' === $subject) {
            // Sans « sub », rien ne permet de reconnaître la même personne à la
            // connexion suivante : mieux vaut refuser que créer un doublon.
            return $this->refuse('Le fournisseur d\'identité n\'a pas fourni d\'identifiant exploitable.');
        }

        $user = $provisioner->provision(
            AuthSource::Oidc,
            $username,
            $subject,
            \is_string($claims['name'] ?? null) ? $claims['name'] : null,
            \is_string($claims['email'] ?? null) ? $claims['email'] : null,
            $this->client->groupsFrom($claims),
        );

        if (!$user->isActive()) {
            return $this->refuse('Ce compte est désactivé.');
        }

        $security->login($user);

        return $this->redirectToRoute('app_dashboard');
    }

    private function refuse(string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_login');
    }

    private function callbackUrl(): string
    {
        return $this->generateUrl('app_oidc_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
