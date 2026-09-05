<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Site;
use App\Repository\OrganizationRepository;
use App\Repository\SiteRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le périmètre de travail courant : une organisation, éventuellement un site.
 *
 * Il vit en session plutôt qu'en paramètre d'URL : un opérateur travaille des
 * heures sur un même site, et le lui faire redéclarer à chaque page — ou
 * traîner un paramètre dans toutes les URL, y compris celles qu'il partage à un
 * collègue qui n'a pas le même périmètre — serait pénible et trompeur.
 *
 * Le contexte est vérifié à chaque lecture : une organisation ou un site
 * supprimé pendant la session ne doit pas figer l'application sur un périmètre
 * qui n'existe plus.
 */
final class ViewContext
{
    private const ORGANIZATION_KEY = 'contexte.organisation';
    private const SITE_KEY = 'contexte.site';

    public function __construct(
        private readonly RequestStack $requests,
        private readonly OrganizationRepository $organizations,
        private readonly SiteRepository $sites,
    ) {
    }

    public function organization(): ?Organization
    {
        $id = $this->read(self::ORGANIZATION_KEY);
        if (null === $id) {
            return null;
        }

        $organization = $this->organizations->find($id);
        if (null === $organization) {
            $this->forget(self::ORGANIZATION_KEY);
            $this->forget(self::SITE_KEY);
        }

        return $organization;
    }

    public function site(): ?Site
    {
        $id = $this->read(self::SITE_KEY);
        if (null === $id) {
            return null;
        }

        $site = $this->sites->find($id);

        // Un site qui n'appartient plus à l'organisation retenue n'a pas de
        // sens : le périmètre serait vide sans que rien ne l'explique.
        if (null === $site || $site->getOrganization() !== $this->organization()) {
            $this->forget(self::SITE_KEY);

            return null;
        }

        return $site;
    }

    public function select(?int $organizationId, ?int $siteId): void
    {
        $session = $this->requests->getSession();

        if (null === $organizationId) {
            $session->remove(self::ORGANIZATION_KEY);
            $session->remove(self::SITE_KEY);

            return;
        }

        $session->set(self::ORGANIZATION_KEY, $organizationId);

        if (null === $siteId) {
            $session->remove(self::SITE_KEY);

            return;
        }

        $site = $this->sites->find($siteId);

        // Choisir un site d'une autre organisation déplace le périmètre entier
        // plutôt que de produire une combinaison incohérente.
        if (null !== $site) {
            $session->set(self::ORGANIZATION_KEY, $site->getOrganization()?->getId());
            $session->set(self::SITE_KEY, $siteId);
        }
    }

    /** Vrai si un périmètre restreint est actif — utile pour le signaler à l'écran. */
    public function isRestricted(): bool
    {
        return null !== $this->organization();
    }

    public function label(): string
    {
        $organization = $this->organization();
        if (null === $organization) {
            return 'Tout le référentiel';
        }

        $site = $this->site();

        return null === $site
            ? $organization->getName()
            : \sprintf('%s — %s', $organization->getName(), $site->getName());
    }

    private function read(string $key): ?int
    {
        $session = $this->requests->getSession();

        return $session->has($key) ? (int) $session->get($key) : null;
    }

    private function forget(string $key): void
    {
        $this->requests->getSession()->remove($key);
    }
}
