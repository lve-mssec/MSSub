<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\AuthSource;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée ou met à jour le compte local miroir d'une identité externe.
 *
 * Un compte est conservé en base même pour une identité d'annuaire ou de SSO :
 * le journal d'audit, les rôles et les préférences se rattachent à une ligne
 * stable. Mais rien de secret n'y est stocké — jamais de mot de passe, et
 * l'identifiant externe sert uniquement à retrouver la même personne après un
 * changement de nom.
 */
final class ExternalUserProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly RoleMapper $roles,
    ) {
    }

    /**
     * @param list<string> $groups
     */
    public function provision(
        AuthSource $source,
        string $username,
        string $externalId,
        ?string $displayName,
        ?string $email,
        array $groups,
    ): User {
        // L'identifiant externe prime sur le nom de connexion : c'est lui qui
        // reste stable quand une personne change de nom ou d'adresse.
        $user = $this->users->findOneBy(['authSource' => $source, 'externalId' => $externalId])
            ?? $this->users->findOneBy(['username' => $username]);

        if (null === $user) {
            $user = (new User())->setUsername($username);
        } elseif (AuthSource::Local === $user->getAuthSource() && AuthSource::Local !== $source) {
            // Un compte local déjà en base garde son mot de passe : le convertir
            // supprimerait le compte de secours sans que personne l'ait demandé.
            //
            // La condition porte sur un compte existant, et pas seulement sur sa
            // source : une entité neuve vaut AuthSource::Local par défaut, si
            // bien qu'un test sur la seule source aurait fait retomber tous les
            // comptes créés depuis l'annuaire dans cette branche — ils
            // naissaient « locaux » et sans mot de passe, donc inutilisables.
            return $this->save($user);
        }

        $user->setAuthSource($source)
            ->setExternalId($externalId)
            ->setUsername($username)
            ->setDisplayName($displayName ?? $user->getDisplayName() ?? $username)
            ->setEmail($email ?? $user->getEmail())
            ->setRoles($this->roles->rolesFor($groups));

        return $this->save($user);
    }

    /**
     * L'horodatage de dernière connexion n'est pas posé ici : il l'est à
     * l'événement de connexion réussie, seul endroit qui vaut pour toutes les
     * sources, y compris les comptes locaux qui ne passent jamais par ici.
     */
    private function save(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
