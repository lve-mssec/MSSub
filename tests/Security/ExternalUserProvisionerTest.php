<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Enum\AuthSource;
use App\Repository\UserRepository;
use App\Security\ExternalUserProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le miroir local d'une identité externe.
 *
 * Ces tests portent sur des règles qui ne se voient qu'à l'usage : ce qui
 * arrive à un compte local homonyme, et sous quelle clé une personne est
 * reconnue quand son identifiant de connexion change.
 */
final class ExternalUserProvisionerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ExternalUserProvisioner $provisioner;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->provisioner = self::getContainer()->get(ExternalUserProvisioner::class);
        $this->users = self::getContainer()->get(UserRepository::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    /**
     * Le compte créé depuis l'annuaire doit porter la bonne source.
     *
     * Une entité neuve vaut AuthSource::Local par défaut ; un garde-fou écrit
     * sans y penser faisait naître tous les comptes d'annuaire « locaux » et
     * sans mot de passe — donc définitivement inutilisables.
     */
    public function testDirectoryAccountIsCreatedWithItsRealSource(): void
    {
        $user = $this->provisioner->provision(
            AuthSource::Ldap,
            'jdupont.test',
            'uid=jdupont.test,ou=users,dc=mssec,dc=local',
            'Jean Dupont',
            'jean@mssec.local',
            ['mssub-admins'],
        );

        self::assertSame(AuthSource::Ldap, $user->getAuthSource());
        self::assertNull($user->getPassword(), 'Aucun secret d\'annuaire ne doit être stocké.');
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertSame('Jean Dupont', $user->getDisplayName());
    }

    /** Le compte de secours ne doit pas être absorbé par un homonyme d'annuaire. */
    public function testExistingLocalAccountIsNeverConverted(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $local = (new User())->setUsername('secours.test')->setAuthSource(AuthSource::Local);
        $local->setPassword($hasher->hashPassword($local, 'un-mot-de-passe-solide'));
        $this->em->persist($local);
        $this->em->flush();

        $user = $this->provisioner->provision(
            AuthSource::Ldap,
            'secours.test',
            'uid=secours,ou=users,dc=mssec,dc=local',
            'Homonyme',
            null,
            ['mssub-admins'],
        );

        self::assertSame(AuthSource::Local, $user->getAuthSource());
        self::assertNotNull($user->getPassword());
        self::assertNotContains('ROLE_ADMIN', $user->getRoles(), 'Un homonyme ne doit pas accorder de privilèges.');
    }

    /** L'identifiant externe prime : une personne renommée reste la même. */
    public function testRenamedAccountIsRecognisedByItsExternalId(): void
    {
        $dn = 'uid=mmartin.test,ou=users,dc=mssec,dc=local';
        $first = $this->provisioner->provision(AuthSource::Ldap, 'mmartin.test', $dn, 'Marie Martin', null, []);
        $id = $first->getId();

        $renamed = $this->provisioner->provision(AuthSource::Ldap, 'mdurand.test', $dn, 'Marie Durand', null, []);

        self::assertSame($id, $renamed->getId(), 'Le changement de nom ne doit pas créer un second compte.');
        self::assertSame('mdurand.test', $renamed->getUsername());
        self::assertCount(0, $this->users->findBy(['username' => 'mmartin.test']));
    }

    /** Les rôles suivent les groupes : perdre un groupe, c'est perdre le rôle. */
    public function testRolesFollowGroupMembershipOnEachLogin(): void
    {
        $dn = 'uid=promu.test,ou=users,dc=mssec,dc=local';

        $user = $this->provisioner->provision(AuthSource::Ldap, 'promu.test', $dn, null, null, ['mssub-admins']);
        self::assertContains('ROLE_ADMIN', $user->getRoles());

        $user = $this->provisioner->provision(AuthSource::Ldap, 'promu.test', $dn, null, null, []);
        self::assertNotContains('ROLE_ADMIN', $user->getRoles(), 'Un droit retiré dans l\'annuaire doit disparaître ici.');
    }
}
