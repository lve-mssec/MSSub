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
use App\Service\AllocationService;
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
    ): Response {
        $blocks = [];
        foreach ($organizations->findBy([], ['name' => 'ASC']) as $organization) {
            \assert($organization instanceof Organization);
            foreach ($subnets->findRoots($organization) as $root) {
                $blocks[] = [
                    'organization' => $organization,
                    'subnet' => $root,
                    'largestFree' => $allocation->largestFreeSubnetIn($root),
                ];
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'nav' => 'dashboard',
            'counts' => [
                'organizations' => $organizations->count([]),
                'sites' => $sites->count([]),
                'subnets' => $subnets->count([]),
                'addresses' => $addresses->count([]),
                'vlans' => $vlans->count([]),
                'devices' => $devices->count([]),
            ],
            'blocks' => $blocks,
        ]);
    }
}
