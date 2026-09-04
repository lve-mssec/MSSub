<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Site;
use App\Form\Admin\SiteType;
use App\Repository\OrganizationRepository;
use App\Repository\SiteRepository;
use App\Repository\SubnetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/administration/sites')]
final class SiteController extends AbstractController
{
    #[Route('', name: 'app_admin_site')]
    public function index(SiteRepository $sites, SubnetRepository $subnets): Response
    {
        $rows = [];
        foreach ($sites->findBy([], ['name' => 'ASC']) as $site) {
            $rows[] = [
                'site' => $site,
                'vlans' => \count($site->getVlans()),
                'devices' => \count($site->getDevices()),
                'subnets' => $subnets->count(['site' => $site]),
            ];
        }

        return $this->render('admin/site.html.twig', ['nav' => 'admin', 'rows' => $rows]);
    }

    #[Route('/nouveau', name: 'app_admin_site_new')]
    public function new(Request $request, EntityManagerInterface $em, OrganizationRepository $organizations): Response
    {
        if (0 === $organizations->count([])) {
            $this->addFlash('error', 'Déclarez d\'abord une organisation : un site lui est nécessairement rattaché.');

            return $this->redirectToRoute('app_admin_organization');
        }

        return $this->edit($request, new Site(), $em);
    }

    #[Route('/{id}/modifier', name: 'app_admin_site_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Site $site, EntityManagerInterface $em): Response
    {
        $isNew = null === $site->getId();
        $form = $this->createForm(SiteType::class, $site);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($site);
            $em->flush();
            $this->addFlash('success', \sprintf('Site « %s » %s.', $site->getName(), $isNew ? 'créé' : 'mis à jour'));

            return $this->redirectToRoute('app_admin_site');
        }

        return $this->render('admin/form.html.twig', [
            'nav' => 'admin',
            'form' => $form,
            'title' => $isNew ? 'Nouveau site' : 'Modifier '.$site->getName(),
            'back' => $this->generateUrl('app_admin_site'),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'app_admin_site_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Site $site, EntityManagerInterface $em, SubnetRepository $subnets): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-site-'.$site->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        // Les rattachements se dénouent au lieu de bloquer : un site qui ferme
        // ne doit pas empêcher de garder l'historique de ses réseaux, qui
        // deviennent simplement non localisés.
        $detached = $subnets->count(['site' => $site]);
        $name = $site->getName();

        $em->remove($site);
        $em->flush();

        $this->addFlash('success', 0 === $detached
            ? \sprintf('Site « %s » supprimé.', $name)
            : \sprintf('Site « %s » supprimé ; %d réseau(x) sont désormais sans site.', $name, $detached));

        return $this->redirectToRoute('app_admin_site');
    }
}
