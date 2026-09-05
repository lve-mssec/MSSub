<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrganizationRepository;
use App\Repository\SiteRepository;
use App\Service\ViewContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ContextController extends AbstractController
{
    #[Route('/contexte', name: 'app_context', methods: ['POST'])]
    public function select(Request $request, ViewContext $context): Response
    {
        if (!$this->isCsrfTokenValid('contexte', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $organization = $request->request->get('organisation');
        $site = $request->request->get('site');

        $context->select(
            '' === $organization || null === $organization ? null : (int) $organization,
            '' === $site || null === $site ? null : (int) $site,
        );

        // Retour à la page d'où l'on vient : changer de périmètre ne doit pas
        // faire perdre l'écran sur lequel on travaillait.
        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_dashboard'));
    }

    /**
     * La barre de périmètre, incluse dans toutes les pages.
     *
     * Rendue par un sous-appel plutôt que par des variables passées depuis
     * chaque contrôleur : elle n'a rien à voir avec ce que la page affiche, et
     * la faire transiter partout obligerait à y penser à chaque nouvel écran.
     */
    public function bar(OrganizationRepository $organizations, SiteRepository $sites, ViewContext $context): Response
    {
        $organization = $context->organization();

        return $this->render('partials/context_bar.html.twig', [
            'organizations' => $organizations->findBy([], ['name' => 'ASC']),
            'sites' => null === $organization
                ? []
                : $sites->findBy(['organization' => $organization], ['name' => 'ASC']),
            'current_organization' => $organization,
            'current_site' => $context->site(),
        ]);
    }
}
