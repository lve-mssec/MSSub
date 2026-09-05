<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Repository\DeviceRepository;
use App\Repository\IpAddressRepository;
use App\Repository\OrganizationRepository;
use App\Repository\SiteRepository;
use App\Repository\SubnetRepository;
use App\Repository\VlanRepository;
use App\Entity\Site;
use App\Entity\Subnet;
use App\Service\AllocationService;
use App\Service\ViewContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(
        OrganizationRepository $organizations,
        SiteRepository $sites,
        SubnetRepository $subnets,
        IpAddressRepository $addresses,
        VlanRepository $vlans,
        DeviceRepository $devices,
        AllocationService $allocation,
        ViewContext $context,
    ): Response {
        $scope = $context->organization();
        $site = $context->site();

        $blocks = [];
        foreach (null === $scope ? $organizations->findBy([], ['name' => 'ASC']) : [$scope] as $organization) {
            \assert($organization instanceof Organization);
            foreach ($subnets->findRoots($organization) as $root) {
                if (null !== $site && !$this->touches($root, $site, $subnets)) {
                    continue;
                }

                $blocks[] = [
                    'organization' => $organization,
                    'subnet' => $root,
                    'largestFree' => $allocation->largestFreeSubnetIn($root),
                ];
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'nav' => 'dashboard',
            'counts' => $this->counts($scope, $site, $organizations, $sites, $subnets, $addresses, $vlans, $devices),
            'blocks' => $blocks,
            'context' => $context->label(),
            'restricted' => $context->isRestricted(),
        ]);
    }

    /**
     * Vrai si ce bloc, ou l'un de ses descendants, releve du site.
     *
     * Un bloc racine ne porte presque jamais de site : le masquer sur ce seul
     * critere ferait disparaitre du tableau de bord des plans entiers qui, eux,
     * appartiennent bien au site.
     */
    private function touches(Subnet $root, Site $site, SubnetRepository $subnets): bool
    {
        if ($root->getEffectiveSite()?->getId() === $site->getId()) {
            return true;
        }

        foreach ($subnets->findChildren($root) as $child) {
            if ($this->touches($child, $site, $subnets)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les compteurs du perimetre courant.
     *
     * @return array<string, int>
     */
    private function counts(
        ?Organization $scope,
        ?Site $site,
        OrganizationRepository $organizations,
        SiteRepository $sites,
        SubnetRepository $subnets,
        IpAddressRepository $addresses,
        VlanRepository $vlans,
        DeviceRepository $devices,
    ): array {
        if (null === $scope) {
            return [
                'organizations' => $organizations->count([]),
                'sites' => $sites->count([]),
                'subnets' => $subnets->count([]),
                'addresses' => $addresses->count([]),
                'vlans' => $vlans->count([]),
                'devices' => $devices->count([]),
            ];
        }

        $organizationSubnets = $subnets->findBy(['organization' => $scope]);

        // Le site effectif ne se calcule pas en SQL : il remonte la hierarchie.
        // Le filtrage se fait donc en memoire, sur les seuls reseaux de
        // l'organisation retenue — un ensemble deja borne par le perimetre.
        $retained = null === $site
            ? $organizationSubnets
            : array_filter(
                $organizationSubnets,
                static fn (Subnet $subnet): bool => $subnet->getEffectiveSite()?->getId() === $site->getId(),
            );

        $addressCount = 0;
        foreach ($retained as $subnet) {
            $addressCount += $addresses->countBySubnet($subnet);
        }

        return [
            'organizations' => 1,
            'sites' => null === $site ? $sites->count(['organization' => $scope]) : 1,
            'subnets' => \count($retained),
            'addresses' => $addressCount,
            'vlans' => null === $site ? \count($this->vlansOf($scope, $sites, $vlans)) : \count($site->getVlans()),
            'devices' => null === $site ? \count($this->devicesOf($scope, $sites)) : \count($site->getDevices()),
        ];
    }

    /** @return list<object> */
    private function vlansOf(Organization $scope, SiteRepository $sites, VlanRepository $vlans): array
    {
        $found = [];
        foreach ($sites->findBy(['organization' => $scope]) as $site) {
            foreach ($site->getVlans() as $vlan) {
                $found[] = $vlan;
            }
        }

        return $found;
    }

    /** @return list<object> */
    private function devicesOf(Organization $scope, SiteRepository $sites): array
    {
        $found = [];
        foreach ($sites->findBy(['organization' => $scope]) as $site) {
            foreach ($site->getDevices() as $device) {
                $found[] = $device;
            }
        }

        return $found;
    }
}
