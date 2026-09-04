<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Vlan;
use App\Form\Admin\VlanType;
use App\Repository\SubnetRepository;
use App\Repository\VlanRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/administration/vlan')]
final class VlanController extends AbstractController
{
    #[Route('', name: 'app_admin_vlan')]
    public function index(VlanRepository $vlans, SubnetRepository $subnets): Response
    {
        $rows = [];
        foreach ($vlans->findBy([], ['number' => 'ASC']) as $vlan) {
            $rows[] = ['vlan' => $vlan, 'subnets' => $subnets->count(['vlan' => $vlan])];
        }

        return $this->render('admin/vlan.html.twig', ['nav' => 'admin', 'rows' => $rows]);
    }

    #[Route('/nouveau', name: 'app_admin_vlan_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->edit($request, new Vlan(), $em);
    }

    #[Route('/{id}/modifier', name: 'app_admin_vlan_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Vlan $vlan, EntityManagerInterface $em): Response
    {
        $isNew = null === $vlan->getId();
        $form = $this->createForm(VlanType::class, $vlan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $em->persist($vlan);
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                // La contrainte est en base : c'est elle qui fait foi, y compris
                // face à deux saisies simultanées qu'une vérification préalable
                // laisserait passer toutes les deux.
                $form->get('number')->addError(new FormError(
                    \sprintf('Le VLAN %d existe déjà sur ce site.', $vlan->getNumber()),
                ));

                return $this->renderForm($form, $isNew, $vlan);
            }

            $this->addFlash('success', \sprintf('VLAN %d %s.', $vlan->getNumber(), $isNew ? 'créé' : 'mis à jour'));

            return $this->redirectToRoute('app_admin_vlan');
        }

        return $this->renderForm($form, $isNew, $vlan);
    }

    #[Route('/{id}/supprimer', name: 'app_admin_vlan_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Vlan $vlan, EntityManagerInterface $em, SubnetRepository $subnets): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-vlan-'.$vlan->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $detached = $subnets->count(['vlan' => $vlan]);
        $label = (string) $vlan;

        $em->remove($vlan);
        $em->flush();

        $this->addFlash('success', 0 === $detached
            ? \sprintf('%s supprimé.', $label)
            : \sprintf('%s supprimé ; %d réseau(x) n\'y sont plus rattachés.', $label, $detached));

        return $this->redirectToRoute('app_admin_vlan');
    }

    private function renderForm(\Symfony\Component\Form\FormInterface $form, bool $isNew, Vlan $vlan): Response
    {
        return $this->render('admin/form.html.twig', [
            'nav' => 'admin',
            'form' => $form,
            'title' => $isNew ? 'Nouveau VLAN' : 'Modifier le VLAN '.$vlan->getNumber(),
            'back' => $this->generateUrl('app_admin_vlan'),
        ]);
    }
}
