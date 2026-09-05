<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Site;
use App\Entity\Subnet;
use App\Repository\IpAddressRepository;
use App\Repository\SubnetRepository;
use App\Service\IpAllocator;
use App\Service\ViewContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La fiche d'un site : ce qu'il porte, et où il en est.
 *
 * Les réseaux retenus sont ceux dont le site effectif est celui-ci, héritage
 * compris. Se limiter aux réseaux déclarant explicitement le site donnerait une
 * fiche presque vide, puisqu'un plan bien tenu ne répète pas le site sur chaque
 * sous-réseau.
 */
#[IsGranted('ROLE_USER')]
final class SiteController extends AbstractController
{
    #[Route('/sites/{id}', name: 'app_site_show', requirements: ['id' => '\d+'])]
    public function show(
        Site $site,
        SubnetRepository $subnets,
        IpAddressRepository $addresses,
        IpAllocator $allocator,
        ViewContext $context,
    ): Response {
        $organization = $site->getOrganization();
        $taken = $addresses->countTakenGroupedBySubnet();

        $rows = [];
        $usedTotal = 0;
        $usableTotal = 0;

        foreach ($subnets->findBy(['organization' => $organization], ['networkAddress' => 'ASC', 'prefixLength' => 'ASC']) as $subnet) {
            \assert($subnet instanceof Subnet);

            if ($subnet->getEffectiveSite()?->getId() !== $site->getId()) {
                continue;
            }

            $used = $taken[$subnet->getId()] ?? 0;
            $usage = $subnet->isContainer() ? null : $allocator->usage(
                (string) $subnet->getNetworkAddress(),
                $subnet->getPrefixLength(),
                $used,
            );

            // Les conteneurs sont exclus du total : additionner un bloc et les
            // sous-réseaux qu'il contient compterait deux fois les mêmes
            // adresses, et gonflerait le dénominateur sans rien apprendre.
            if (null !== $usage && is_numeric($usage['usable'])) {
                $usedTotal += $usage['used'];
                $usableTotal += (int) $usage['usable'];
            }

            $rows[] = ['subnet' => $subnet, 'usage' => $usage, 'inherited' => $subnet->inheritsSite()];
        }

        return $this->render('site/show.html.twig', [
            'nav' => 'subnets',
            'site' => $site,
            'rows' => $rows,
            'used' => $usedTotal,
            'usable' => $usableTotal,
            'percent' => $usableTotal > 0 ? round($usedTotal / $usableTotal * 100, 2) : 0.0,
            'in_context' => $context->site()?->getId() === $site->getId(),
        ]);
    }
}
