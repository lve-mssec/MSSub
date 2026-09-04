<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IpAddress;
use App\Entity\Subnet;
use App\Enum\IpStatus;
use App\Exception\AllocationException;
use App\Form\IpAddressType;
use App\Service\AllocationService;
use App\Service\IpTools;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OPERATOR')]
final class IpAddressController extends AbstractController
{
    #[Route('/reseaux/{id}/adresses/nouvelle', name: 'app_address_new', requirements: ['id' => '\d+'])]
    public function new(
        Request $request,
        Subnet $subnet,
        EntityManagerInterface $em,
        AllocationService $allocation,
        IpTools $ip,
    ): Response {
        if ($subnet->isContainer()) {
            $this->addFlash('error', \sprintf('%s est un conteneur : il n\'accueille pas d\'adresses.', $subnet->getCidr()));

            return $this->redirectToRoute('app_subnet_show', ['id' => $subnet->getId()]);
        }

        $address = (new IpAddress())->setSubnet($subnet)->setStatus(IpStatus::Used);

        // Pré-remplir avec la prochaine libre évite la saisie la plus fréquente,
        // et rend visible ce que le moteur d'allocation propose.
        $address->setAddress($request->query->get('adresse') ?? ($allocation->freeAddressesIn($subnet, 1)[0] ?? null));

        $form = $this->createForm(IpAddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->isConsistent($form, $subnet, $address, $ip, $em)) {
            $em->persist($address);
            $em->flush();

            $this->addFlash('success', \sprintf('Adresse %s enregistrée.', $address->getAddress()));

            return $this->redirectToRoute('app_subnet_show', ['id' => $subnet->getId()]);
        }

        return $this->render('address/form.html.twig', [
            'nav' => 'subnets',
            'form' => $form,
            'subnet' => $subnet,
            'address' => null,
        ]);
    }

    /**
     * Réserve la prochaine adresse libre, sans passer par le formulaire.
     *
     * C'est le geste le plus courant d'un IPAM : « donne-moi une adresse ».
     */
    #[Route('/reseaux/{id}/adresses/reserver', name: 'app_address_reserve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reserve(
        Request $request,
        Subnet $subnet,
        EntityManagerInterface $em,
        AllocationService $allocation,
    ): Response {
        if (!$this->isCsrfTokenValid('reserver-'.$subnet->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        try {
            $next = $allocation->nextFreeAddressIn($subnet);
        } catch (AllocationException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_subnet_show', ['id' => $subnet->getId()]);
        }

        $em->persist(
            (new IpAddress())
                ->setSubnet($subnet)
                ->setAddress($next)
                ->setStatus(IpStatus::Reserved)
                ->setDescription('Réservée depuis le portail.'),
        );
        $em->flush();

        $this->addFlash('success', \sprintf('Adresse %s réservée.', $next));

        return $this->redirectToRoute('app_subnet_show', ['id' => $subnet->getId()]);
    }

    #[Route('/adresses/{id}/modifier', name: 'app_address_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, IpAddress $address, EntityManagerInterface $em, IpTools $ip): Response
    {
        $subnet = $address->getSubnet();
        \assert($subnet instanceof Subnet);

        $form = $this->createForm(IpAddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->isConsistent($form, $subnet, $address, $ip, $em)) {
            $em->flush();
            $this->addFlash('success', \sprintf('Adresse %s mise à jour.', $address->getAddress()));

            return $this->redirectToRoute('app_subnet_show', ['id' => $subnet->getId()]);
        }

        return $this->render('address/form.html.twig', [
            'nav' => 'subnets',
            'form' => $form,
            'subnet' => $subnet,
            'address' => $address,
        ]);
    }

    #[Route('/adresses/{id}/supprimer', name: 'app_address_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, IpAddress $address, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-adresse-'.$address->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $subnet = $address->getSubnet();
        $value = (string) $address->getAddress();

        $em->remove($address);
        $em->flush();

        $this->addFlash('success', \sprintf('Adresse %s supprimée.', $value));

        return $this->redirectToRoute('app_subnet_show', ['id' => $subnet?->getId()]);
    }

    /**
     * Deux contrôles que le formulaire seul ne peut pas faire : l'adresse doit
     * tomber dans le réseau, et n'y figurer qu'une fois.
     */
    private function isConsistent(
        FormInterface $form,
        Subnet $subnet,
        IpAddress $address,
        IpTools $ip,
        EntityManagerInterface $em,
    ): bool {
        $value = (string) $address->getAddress();
        $ok = true;

        if (!$ip->contains((string) $subnet->getNetworkAddress(), $subnet->getPrefixLength(), $value)) {
            $form->get('address')->addError(new FormError(
                \sprintf('%s est hors du réseau %s.', $value, $subnet->getCidr()),
            ));
            $ok = false;
        }

        $existing = $em->getRepository(IpAddress::class)->findOneBy(['subnet' => $subnet, 'address' => $value]);
        if (null !== $existing && $existing !== $address) {
            $form->get('address')->addError(new FormError(
                \sprintf('%s est déjà documentée dans ce réseau.', $value),
            ));
            $ok = false;
        }

        return $ok;
    }
}
