<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\AuthSource;
use App\Form\Admin\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des comptes.
 *
 * Deux garde-fous gouvernent ces actions, et ils visent le même accident : se
 * retrouver sans aucun administrateur, donc sans moyen de reprendre la main
 * autrement qu'en ligne de commande sur le serveur.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/administration/comptes')]
final class UserController extends AbstractController
{
    #[Route('', name: 'app_admin_user')]
    public function index(UserRepository $users): Response
    {
        return $this->render('admin/user.html.twig', [
            'nav' => 'admin',
            'users' => $users->findBy([], ['username' => 'ASC']),
            'admins' => $this->countAdmins($users),
        ]);
    }

    #[Route('/nouveau', name: 'app_admin_user_new')]
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, UserRepository $users): Response
    {
        $user = (new User())->setAuthSource(AuthSource::Local)->setRoles([User::ROLE_USER]);

        return $this->handle($request, $user, $em, $hasher, $users, true);
    }

    #[Route('/{id}/modifier', name: 'app_admin_user_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, User $user, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, UserRepository $users): Response
    {
        return $this->handle($request, $user, $em, $hasher, $users, false);
    }

    #[Route('/{id}/supprimer', name: 'app_admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-compte-'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');

            return $this->redirectToRoute('app_admin_user');
        }

        // Il n'y a pas de contrôle « dernier administrateur » ici : l'auteur de
        // la suppression est lui-même un administrateur actif, donc supprimer
        // quelqu'un d'autre en laisse toujours au moins un. Le seul cas
        // dangereux est la suppression de son propre compte, déjà écartée.

        $username = $user->getUsername();
        $em->remove($user);
        $em->flush();

        $this->addFlash('success', \sprintf('Compte « %s » supprimé.', $username));

        return $this->redirectToRoute('app_admin_user');
    }

    private function handle(
        Request $request,
        User $user,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        UserRepository $users,
        bool $isNew,
    ): Response {
        $local = AuthSource::Local === $user->getAuthSource();

        // L'état est relevé avant que le formulaire n'écrive dans l'entité.
        // Interroger l'objet après coup reviendrait à demander « est-il encore
        // administrateur ? » à une entité qui ne l'est déjà plus : le contrôle
        // ne se déclencherait jamais, et le dernier administrateur perdrait ses
        // droits sans rien voir.
        $wasProtectedAdmin = !$isNew && $this->isLastAdmin($user, $users);

        $form = $this->createForm(UserType::class, $user, [
            'with_password' => $local,
            'new' => $isNew,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $stillAdmin = \in_array(User::ROLE_ADMIN, $user->getRoles(), true);

            if ($wasProtectedAdmin && !$stillAdmin) {
                $form->get('roles')->addError(new \Symfony\Component\Form\FormError(
                    'C\'est le dernier compte administrateur : lui retirer ce rôle fermerait l\'administration.',
                ));
            } elseif ($wasProtectedAdmin && !$user->isActive()) {
                $form->get('active')->addError(new \Symfony\Component\Form\FormError(
                    'C\'est le dernier compte administrateur : le désactiver fermerait l\'administration.',
                ));
            } else {
                if ($local) {
                    $password = $form->get('password')->getData();
                    if (\is_string($password) && '' !== $password) {
                        $user->setPassword($hasher->hashPassword($user, $password));
                    }
                }

                $em->persist($user);
                $em->flush();

                $this->addFlash('success', \sprintf('Compte « %s » %s.', $user->getUsername(), $isNew ? 'créé' : 'mis à jour'));

                return $this->redirectToRoute('app_admin_user');
            }
        }

        return $this->render('admin/user_form.html.twig', [
            'nav' => 'admin',
            'form' => $form,
            'user_edited' => $isNew ? null : $user,
        ]);
    }

    /**
     * Vrai si retirer ce compte des administrateurs ne laisserait personne.
     *
     * Un compte inactif ne compte pas : il ne peut pas se connecter, donc il ne
     * garantit aucun accès.
     */
    private function isLastAdmin(User $user, UserRepository $users): bool
    {
        if (!\in_array(User::ROLE_ADMIN, $user->getRoles(), true) || !$user->isActive()) {
            return false;
        }

        return 1 === $this->countAdmins($users);
    }

    private function countAdmins(UserRepository $users): int
    {
        $count = 0;
        foreach ($users->findBy(['active' => true]) as $candidate) {
            if (\in_array(User::ROLE_ADMIN, $candidate->getRoles(), true)) {
                ++$count;
            }
        }

        return $count;
    }
}
