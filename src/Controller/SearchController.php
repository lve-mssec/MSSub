<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Repository\IpAddressRepository;
use App\Repository\OrganizationRepository;
use App\Repository\SubnetRepository;
use App\Service\IpTools;
use App\Service\ViewContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    #[Route('/recherche', name: 'app_search')]
    public function search(
        Request $request,
        OrganizationRepository $organizations,
        SubnetRepository $subnets,
        IpAddressRepository $addresses,
        IpTools $ip,
        ViewContext $context,
    ): Response {
        $scope = $context->organization();
        $query = trim((string) $request->query->get('ip', ''));
        $error = null;
        $results = [];
        $documented = [];

        if ('' !== $query) {
            try {
                $ip->pack($query);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }

            if (null === $error) {
                foreach (null === $scope ? $organizations->findBy([], ['name' => 'ASC']) : [$scope] as $organization) {
                    \assert($organization instanceof Organization);
                    $chain = $subnets->findContainingChain($query, $organization);
                    if ([] !== $chain) {
                        $results[] = ['organization' => $organization, 'chain' => $chain];
                    }
                }
                $documented = $addresses->findByAddress($query);
            }
        }

        return $this->render('search/index.html.twig', [
            'nav' => 'search',
            'query' => $query,
            'error' => $error,
            'results' => $results,
            'documented' => $documented,
            'context' => $context->label(),
            // La recherche suit l'organisation mais ignore le site : une adresse
            // se cherche parce qu'on ne sait pas d'ou elle vient, et la masquer
            // au motif qu'elle appartient a un autre site irait contre l'usage.
            'restricted' => null !== $scope,
        ]);
    }
}
