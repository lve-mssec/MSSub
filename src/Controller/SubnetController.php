<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\Subnet;
use App\Repository\IpAddressRepository;
use App\Repository\OrganizationRepository;
use App\Repository\SubnetRepository;
use App\Service\AllocationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SubnetController extends AbstractController
{
    #[Route('/reseaux', name: 'app_subnet_index')]
    public function index(OrganizationRepository $organizations, SubnetRepository $subnets): Response
    {
        $trees = [];
        foreach ($organizations->findBy([], ['name' => 'ASC']) as $organization) {
            \assert($organization instanceof Organization);
            $trees[] = [
                'organization' => $organization,
                'roots' => array_map(
                    fn (Subnet $root): array => $this->buildTree($root, $subnets),
                    $subnets->findRoots($organization),
                ),
            ];
        }

        return $this->render('subnet/index.html.twig', [
            'nav' => 'subnets',
            'trees' => $trees,
        ]);
    }

    #[Route('/reseaux/{id}', name: 'app_subnet_show', requirements: ['id' => '\d+'])]
    public function show(
        Subnet $subnet,
        SubnetRepository $subnets,
        IpAddressRepository $addresses,
        AllocationService $allocation,
    ): Response {
        // La chaine des blocs parents, du plus large au plus fin.
        $ancestors = [];
        for ($node = $subnet->getParent(); null !== $node; $node = $node->getParent()) {
            array_unshift($ancestors, $node);
        }

        $isContainer = $subnet->isContainer();

        return $this->render('subnet/show.html.twig', [
            'nav' => 'subnets',
            'subnet' => $subnet,
            'ancestors' => $ancestors,
            'children' => $subnets->findChildren($subnet),
            'addresses' => $isContainer ? [] : $addresses->findBySubnet($subnet),
            'usage' => $isContainer ? null : $allocation->usageOf($subnet),
            'freeAddresses' => $isContainer ? [] : $allocation->freeAddressesIn($subnet, 5),
            'freeBlocks' => $isContainer ? $this->suggestBlocks($subnet, $allocation) : [],
        ]);
    }

    /**
     * @return array{subnet: Subnet, children: list<array<string, mixed>>}
     */
    private function buildTree(Subnet $node, SubnetRepository $subnets): array
    {
        return [
            'subnet' => $node,
            'children' => array_map(
                fn (Subnet $child): array => $this->buildTree($child, $subnets),
                $subnets->findChildren($node),
            ),
        ];
    }

    /**
     * Quelques tailles usuelles, pour repondre a « qu'est-ce qui reste ? » sans
     * obliger l'operateur a deviner.
     *
     * @return list<array{prefix: int, cidr: string}>
     */
    private function suggestBlocks(Subnet $container, AllocationService $allocation): array
    {
        $suggestions = [];
        foreach ([24, 26, 28, 30] as $prefix) {
            if ($prefix <= $container->getPrefixLength()) {
                continue;
            }
            $free = $allocation->freeSubnetsIn($container, $prefix, 1);
            if ([] !== $free) {
                $suggestions[] = ['prefix' => $prefix, 'cidr' => $free[0]];
            }
        }

        return $suggestions;
    }
}
