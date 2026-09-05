<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\Subnet;
use App\Repository\IpAddressRepository;
use App\Repository\OrganizationRepository;
use App\Repository\SubnetRepository;
use App\Exception\AllocationException;
use App\Form\SubnetType;
use App\Entity\Site;
use App\Service\AllocationService;
use App\Service\IpAllocator;
use App\Service\ViewContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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
        ViewContext $context,
    ): Response {
        // Une seule requete pour toute l'occupation : l'arborescence peut
        // compter des centaines de lignes, un comptage par ligne serait ruineux.
        $taken = $addresses->countTakenGroupedBySubnet();

        $scope = $context->organization();
        $selectedSite = $context->site();

        $trees = [];
        foreach (null === $scope ? $organizations->findBy([], ['name' => 'ASC']) : [$scope] as $organization) {
            \assert($organization instanceof Organization);

            $rows = [];
            foreach ($subnets->findRoots($organization) as $root) {
                $this->flatten($root, 0, null, $subnets, $taken, $ipAllocator, $rows);
            }

            $groups = $this->groupBySite($rows, $selectedSite);

            if ([] !== $groups || null === $selectedSite) {
                $trees[] = ['organization' => $organization, 'groups' => $groups];
            }
        }

        return $this->render('subnet/index.html.twig', [
            'nav' => 'subnets',
            'trees' => $trees,
            'context' => $context->label(),
            'restricted' => $context->isRestricted(),
        ]);
    }

    /**
     * Regroupe les lignes par site effectif.
     *
     * Le regroupement suit la realite du plan : un bloc « Paris » et tout ce
     * qu'il contient forment un groupe, meme si les sous-reseaux ne declarent
     * pas leur site. Le bloc racine sans site, lui, se retrouve seul dans
     * « Sans site » — ce qui est exact, et rend visible ce qui n'est rattache
     * a rien.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{site: Site|null, rows: list<array<string, mixed>>}>
     */
    private function groupBySite(array $rows, ?Site $only): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $site = $row['subnet']->getEffectiveSite();

            if (null !== $only && $site?->getId() !== $only->getId()) {
                continue;
            }

            $key = null === $site ? 'aucun' : (string) $site->getId();
            $groups[$key] ??= ['site' => $site, 'rows' => []];
            $groups[$key]['rows'][] = $row;
        }

        foreach ($groups as $key => $group) {
            $depths = array_column($group['rows'], 'depth');
            $base = [] === $depths ? 0 : min($depths);
            $present = array_flip(array_map(
                static fn (array $row): int => (int) $row['subnet']->getId(),
                $group['rows'],
            ));

            foreach ($group['rows'] as $index => $row) {
                $groups[$key]['rows'][$index]['indent'] = $row['depth'] - $base;

                // Un parent reste dans un autre groupe lorsqu'il ne porte pas le
                // meme site : la ligne devient alors une racine de ce groupe,
                // sans quoi le pliage tenterait de se rattacher a une ligne
                // absente de la table.
                if (null !== $row['parentId'] && !isset($present[$row['parentId']])) {
                    $groups[$key]['rows'][$index]['parentId'] = null;
                }
            }
        }

        return array_values($groups);
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


    #[Route('/reseaux/nouveau', name: 'app_subnet_new')]
    #[IsGranted('ROLE_OPERATOR')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        OrganizationRepository $organizations,
        AllocationService $allocation,
    ): Response {
        $organization = $this->resolveOrganization($request, $organizations);
        if (null === $organization) {
            $this->addFlash('error', "Aucune organisation n'est déclarée : impossible de créer un réseau.");

            return $this->redirectToRoute('app_subnet_index');
        }

        $subnet = (new Subnet())->setOrganization($organization);
        $form = $this->createForm(SubnetType::class, $subnet, [
            'organization' => $organization,
            'with_cidr' => true,
        ]);
        $form->handleRequest($request);

        // La derivation precede volontairement isValid() : la validation porte
        // sur l'entite, et celle-ci n'est complete qu'une fois le CIDR decoupe.
        if ($form->isSubmitted()) {
            $cidr = trim((string) $form->get('cidr')->getData());

            try {
                // C'est le moteur d'allocation qui tranche : il refuse un
                // doublon ou un chevauchement, et designe le bloc parent.
                $prepared = $allocation->prepare($organization, $cidr);

                $subnet->setVersion($prepared['version'])
                    ->setNetworkAddress($prepared['network'])
                    ->setLastAddress($prepared['last'])
                    ->setPrefixLength($prepared['prefix'])
                    ->setParent($prepared['parent']);

                // Un bloc intercale reprend les reseaux qu'il englobe : sans
                // cela, ils resteraient accroches a l'ancien parent et
                // l'arborescence mentirait.
                foreach ($prepared['children'] as $orphan) {
                    if ($orphan->getParent() === $prepared['parent']) {
                        $orphan->setParent($subnet);
                    }
                }

                if ($form->isValid()) {
                    $em->persist($subnet);
                    $em->flush();

                    $this->addFlash('success', \sprintf('Réseau %s créé.', $subnet->getCidr()));

                    return $this->redirectToRoute('app_subnet_show', ['id' => $subnet->getId()]);
                }
            } catch (AllocationException|\InvalidArgumentException $e) {
                $form->get('cidr')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('subnet/form.html.twig', [
            'nav' => 'subnets',
            'form' => $form,
            'subnet' => null,
            'organization' => $organization,
        ]);
    }

    #[Route('/reseaux/{id}/modifier', name: 'app_subnet_edit', requirements: ['id' => '\\d+'])]
    #[IsGranted('ROLE_OPERATOR')]
    public function edit(Request $request, Subnet $subnet, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SubnetType::class, $subnet, [
            'organization' => $subnet->getOrganization(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', \sprintf('Réseau %s mis à jour.', $subnet->getCidr()));

            return $this->redirectToRoute('app_subnet_show', ['id' => $subnet->getId()]);
        }

        return $this->render('subnet/form.html.twig', [
            'nav' => 'subnets',
            'form' => $form,
            'subnet' => $subnet,
            'organization' => $subnet->getOrganization(),
        ]);
    }

    #[Route('/reseaux/{id}/supprimer', name: 'app_subnet_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Subnet $subnet, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-reseau-'.$subnet->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $parent = $subnet->getParent();
        $cidr = $subnet->getCidr();

        // Les enfants remontent d'un cran plutot que de disparaitre avec le
        // parent : supprimer un bloc intermediaire ne doit pas effacer un plan.
        foreach ($subnet->getChildren() as $child) {
            $child->setParent($parent);
        }

        $em->remove($subnet);
        $em->flush();

        $this->addFlash('success', \sprintf('Réseau %s supprimé.', $cidr));

        return $this->redirectToRoute(
            null === $parent ? 'app_subnet_index' : 'app_subnet_show',
            null === $parent ? [] : ['id' => $parent->getId()],
        );
    }

    private function resolveOrganization(Request $request, OrganizationRepository $organizations): ?Organization
    {
        $requested = $request->query->get('organisation');
        if (null !== $requested) {
            return $organizations->find((int) $requested);
        }

        $all = $organizations->findBy([], ['name' => 'ASC'], 2);

        return 1 === \count($all) ? $all[0] : ($all[0] ?? null);
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
