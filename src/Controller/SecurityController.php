<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\LdapDirectory;
use App\Security\OidcClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'app_login')]
    public function login(
        AuthenticationUtils $authenticationUtils,
        OidcClient $oidc,
        LdapDirectory $directory,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'oidc_enabled' => $oidc->isEnabled(),
            'oidc_label' => $oidc->label(),
            'ldap_enabled' => $directory->isEnabled(),
        ]);
    }

    /**
     * Interceptee par le pare-feu : ce corps n'est jamais execute.
     */
    #[Route('/deconnexion', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode est interceptée par la configuration de déconnexion.');
    }
}
