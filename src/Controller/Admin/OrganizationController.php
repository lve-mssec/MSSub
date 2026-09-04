<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Organization;
use App\Form\Admin\OrganizationType;
use App\Repository\OrganizationRepository;
use App\Repository\SubnetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/administration/organisations')]
final class OrganizationController extends AbstractController
{
    #[Route('', name: 'app_admin_organization')]
    public function index(OrganizationRepository $organizations, SubnetRepository $subnets): Response
    {
        $rows = [];
        foreach ($organizations->findBy([], ['name' => 'ASC']) as $organization) {
            $rows[] = [
                'organization' => $organization,
                'sites' => \count($organization->getSites()),
                'subnets' => $subnets->count(['organization' => $organization]),
            ];
        }

        return $this->render('admin/organization.html.twig', ['nav' => 'admin', 'rows' => $rows]);
    }

    #[Route('/nouvelle', name: 'app_admin_organization_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->edit($request, new Organization(), $em);
    }

    #[Route('/{id}/modifier', name: 'app_admin_organization_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Organization $organization, EntityManagerInterface $em): Response
    {
        $isNew = null === $organization->getId();
        $form = $this->createForm(OrganizationType::class, $organization);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($organization);
            $em->flush();
            $this->addFlash('success', \sprintf('Organisation « %s » %s.', $organization->getName(), $isNew ? 'créée' : 'mise à jour'));

            return $this->redirectToRoute('app_admin_organization');
        }

        return $this->render('admin/form.html.twig', [
            'nav' => 'admin',
            'form' => $form,
            'title' => $isNew ? 'Nouvelle organisation' : 'Modifier '.$organization->getName(),
            'back' => $this->generateUrl('app_admin_organization'),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'app_admin_organization_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Request $request,
        Organization $organization,
        EntityManagerInterface $em,
        SubnetRepository $subnets,
    ): Response {
        if (!$this->isCsrfTokenValid('supprimer-organisation-'.$organization->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        // Une organisation porte tout le plan d'adressage : la supprimer avec
        // son contenu effacerait des années de référentiel sur un clic. Elle ne
        // part que vide, et c'est à l'opérateur de vider en connaissance de cause.
        $subnetCount = $subnets->count(['organization' => $organization]);
        if ($subnetCount > 0 || \count($organization->getSites()) > 0) {
            $this->addFlash('error', \sprintf(
                '« %s » porte encore %d réseau(x) et %d site(s) : videz-la d\'abord.',
                $organization->getName(),
                $subnetCount,
                \count($organization->getSites()),
            ));

            return $this->redirectToRoute('app_admin_organization');
        }

        $name = $organization->getName();
        $em->remove($organization);
        $em->flush();
        $this->addFlash('success', \sprintf('Organisation « %s » supprimée.', $name));

        return $this->redirectToRoute('app_admin_organization');
    }
}
