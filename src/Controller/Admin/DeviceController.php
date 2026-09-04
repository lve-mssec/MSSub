<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Device;
use App\Entity\NetworkInterface;
use App\Form\Admin\DeviceType;
use App\Form\Admin\NetworkInterfaceType;
use App\Repository\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/administration/equipements')]
final class DeviceController extends AbstractController
{
    #[Route('', name: 'app_admin_device')]
    public function index(DeviceRepository $devices): Response
    {
        return $this->render('admin/device.html.twig', [
            'nav' => 'admin',
            'devices' => $devices->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/nouveau', name: 'app_admin_device_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->edit($request, new Device(), $em);
    }

    /**
     * Les interfaces se gèrent sur la fiche de l'équipement.
     *
     * Une page « interfaces » globale n'aurait pas de sens : une interface
     * n'existe que portée par un équipement, et la chercher hors de son
     * contexte obligerait à relire son nom d'équipement à chaque ligne.
     */
    #[Route('/{id}/modifier', name: 'app_admin_device_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Device $device, EntityManagerInterface $em): Response
    {
        $isNew = null === $device->getId();
        $form = $this->createForm(DeviceType::class, $device);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($device);
            $em->flush();
            $this->addFlash('success', \sprintf('Équipement « %s » %s.', $device->getName(), $isNew ? 'créé' : 'mis à jour'));

            return $this->redirectToRoute($isNew ? 'app_admin_device_edit' : 'app_admin_device', $isNew ? ['id' => $device->getId()] : []);
        }

        return $this->render('admin/device_form.html.twig', [
            'nav' => 'admin',
            'form' => $form,
            'device' => $isNew ? null : $device,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'app_admin_device_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Device $device, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-equipement-'.$device->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        // Les interfaces disparaissent avec l'équipement, mais les adresses
        // documentées restent : elles décrivent le plan d'adressage, pas le
        // matériel, et survivent au remplacement d'un boîtier.
        $name = $device->getName();
        $em->remove($device);
        $em->flush();

        $this->addFlash('success', \sprintf('Équipement « %s » supprimé ; les adresses documentées sont conservées.', $name));

        return $this->redirectToRoute('app_admin_device');
    }

    #[Route('/{id}/interfaces/nouvelle', name: 'app_admin_interface_new', requirements: ['id' => '\d+'])]
    public function newInterface(Request $request, Device $device, EntityManagerInterface $em): Response
    {
        $interface = (new NetworkInterface())->setDevice($device);

        return $this->editInterface($request, $interface, $em);
    }

    #[Route('/interfaces/{id}/modifier', name: 'app_admin_interface_edit', requirements: ['id' => '\d+'])]
    public function editInterface(Request $request, NetworkInterface $interface, EntityManagerInterface $em): Response
    {
        $device = $interface->getDevice();
        \assert($device instanceof Device);

        $form = $this->createForm(NetworkInterfaceType::class, $interface);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($interface);
            $em->flush();
            $this->addFlash('success', \sprintf('Interface « %s » enregistrée.', $interface->getName()));

            return $this->redirectToRoute('app_admin_device_edit', ['id' => $device->getId()]);
        }

        return $this->render('admin/form.html.twig', [
            'nav' => 'admin',
            'form' => $form,
            'title' => \sprintf('Interface de %s', $device->getName()),
            'back' => $this->generateUrl('app_admin_device_edit', ['id' => $device->getId()]),
        ]);
    }

    #[Route('/interfaces/{id}/supprimer', name: 'app_admin_interface_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteInterface(Request $request, NetworkInterface $interface, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-interface-'.$interface->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $device = $interface->getDevice();
        $name = $interface->getName();

        $em->remove($interface);
        $em->flush();

        $this->addFlash('success', \sprintf('Interface « %s » supprimée.', $name));

        return $this->redirectToRoute('app_admin_device_edit', ['id' => $device?->getId()]);
    }
}
