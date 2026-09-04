<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\Subnet;
use App\Repository\IpAddressRepository;
use App\Repository\OrganizationRepository;
use App\Repository\SubnetRepository;
use App\Service\AllocationService;
use App\Service\IpAllocator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SubnetController extends AbstractController
{
    #[Route('/reseaux', name: 'app_subnet_index')]
    public function index(
        OrganizationRepository $organizations,
        SubnetRepository $subnets,
        IpAddressRepository $addresses,
        IpAllocator $ipAllocator,
    ): Response {
        // Une seule requete pour toute l'occupation : l'arborescence peut
        // compter des centaines de lignes, un comptage par ligne serait ruineux.
        $taken = $addresses->countTakenGroupedBySubnet();

        $trees = [];
        foreach ($organizations->findBy([], ['name' => 'ASC']) as $organization) {
            \assert($organization instanceof Organization);

            $rows = [];
            foreach ($subnets->findRoots($organization) as $root) {
                $this->flatten($root, 0, null, $subnets, $taken, $ipAllocator, $rows);
            }

            $trees[] = ['organization' => $organization, 'rows' => $rows];
        }

        return $this->render('subnet/index.html.twig', [
            'nav' => 'subnets',
            'trees' => $trees,
        ]);
    }

    /**
     * Aplatit l'arborescence en lignes de tableau.
     *
     * Un tableau plutot que des listes imbriquees : c'est la seule facon
     * d'obtenir un zebrage continu, la parite d'une liste imbriquee repartant a
     * zero a chaque niveau. La profondeur est portee par la ligne, et
     * l'indentation la restitue.
     *
     * @param array<int, int>                 $taken
     * @param list<array<string, mixed>>      $rows
     */
    private function flatten(
        Subnet $node,
        int $depth,
        ?int $parentId,
        SubnetRepository $subnets,
        array $taken,
        IpAllocator $ipAllocator,
        array &$rows,
    ): void {
        $children = $subnets->findChildren($node);
        $used = $taken[$node->getId()] ?? 0;

        $rows[] = [
            'subnet' => $node,
            'depth' => $depth,
            'parentId' => $parentId,
            'hasChildren' => [] !== $children,
            'usage' => $node->isContainer() ? null : $ipAllocator->usage(
                (string) $node->getNetworkAddress(),
                $node->getPrefixLength(),
                $used,
            ),
        ];

        foreach ($children as $child) {
            $this->flatten($child, $depth + 1, $node->getId(), $subnets, $taken, $ipAllocator, $rows);
        }
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
