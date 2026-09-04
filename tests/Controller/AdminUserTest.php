<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\AuthSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les garde-fous de la gestion des comptes.
 *
 * Ils visent tous le même accident : se retrouver sans aucun administrateur,
 * donc sans moyen de reprendre la main autrement qu'en ligne de commande sur le
 * serveur. Ces tests existent parce que la première version du contrôle était
 * décorative — il interrogeait l'entité après que le formulaire y avait déjà
 * écrit les nouveaux rôles, si bien qu'il ne se déclenchait jamais.
 */
final class AdminUserTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        // Les comptes déjà présents sont écartés : le test doit décider seul
        // combien d'administrateurs existent.
        foreach ($this->em->getRepository(User::class)->findAll() as $existing) {
            $this->em->remove($existing);
        }
        $this->em->flush();

        $this->admin = $this->givenUser('unique.admin', [User::ROLE_ADMIN]);
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testTheLastAdminCannotLoseTheRole(): void
    {
        $this->submitEdit($this->admin, roles: [User::ROLE_USER], active: true);

        self::assertSelectorTextContains('.field__errors', 'dernier compte administrateur');
        self::assertContains(User::ROLE_ADMIN, $this->stored($this->admin)['roles']);
    }

    public function testTheLastAdminCannotBeDeactivated(): void
    {
        $this->submitEdit($this->admin, roles: [User::ROLE_ADMIN], active: false);

        self::assertSelectorTextContains('.field__errors', 'dernier compte administrateur');
        self::assertTrue($this->stored($this->admin)['active']);
    }

    /** Le verrou saute dès qu'un second administrateur existe. */
    public function testTheRoleCanBeRemovedOnceAnotherAdminExists(): void
    {
        $this->givenUser('second.admin', [User::ROLE_ADMIN]);

        $this->submitEdit($this->admin, roles: [User::ROLE_OPERATOR], active: true);

        self::assertNotContains(User::ROLE_ADMIN, $this->stored($this->admin)['roles']);
    }

    /** Un second administrateur désactivé ne garantit aucun accès. */
    public function testADeactivatedSecondAdminDoesNotUnlock(): void
    {
        $this->givenUser('admin.parti', [User::ROLE_ADMIN], active: false);

        $this->submitEdit($this->admin, roles: [User::ROLE_USER], active: true);

        self::assertSelectorTextContains('.field__errors', 'dernier compte administrateur');
    }

    public function testAnOrdinaryEditIsAccepted(): void
    {
        $this->submitEdit($this->admin, roles: [User::ROLE_ADMIN], active: true, displayName: 'Nom révisé');

        self::assertResponseRedirects('/administration/comptes');
        self::assertSame('Nom révisé', $this->em->getConnection()->fetchOne(
            'SELECT display_name FROM app_user WHERE id = ?',
            [$this->admin->getId()],
        ));
    }

    /** Se supprimer soi-même est refusé, quel que soit le nombre d'administrateurs. */
    public function testDeletingOwnAccountIsRefused(): void
    {
        $this->givenUser('second.admin', [User::ROLE_ADMIN]);

        $this->client->request('POST', '/administration/comptes/'.$this->admin->getId().'/supprimer', [
            '_token' => $this->csrfToken('supprimer-compte-'.$this->admin->getId()),
        ]);

        self::assertNotNull($this->em->getRepository(User::class)->findOneBy(['username' => 'unique.admin']));
    }

    /** Le bouton de suppression ne s'affiche pas sur sa propre ligne. */
    public function testOwnRowOffersNoDeleteButton(): void
    {
        $other = $this->givenUser('collegue', [User::ROLE_USER]);

        $crawler = $this->client->request('GET', '/administration/comptes');

        self::assertCount(0, $crawler->filter('form[action$="/comptes/'.$this->admin->getId().'/supprimer"]'));
        self::assertCount(1, $crawler->filter('form[action$="/comptes/'.$other->getId().'/supprimer"]'));
    }

    /** @param list<string> $roles */
    private function submitEdit(User $user, array $roles, bool $active, ?string $displayName = null): void
    {
        $crawler = $this->client->request('GET', '/administration/comptes/'.$user->getId().'/modifier');

        // Soumission en clair plutôt que par le formulaire du crawler : les
        // cases à cocher d'un choix multiple étendu y sont autant de champs
        // distincts, et les manipuler une à une rendrait le test illisible.
        $payload = [
            'username' => $user->getUsername(),
            'displayName' => $displayName ?? $user->getDisplayName() ?? '',
            'email' => '',
            'roles' => $roles,
            '_token' => $crawler->filter('input[name="user[_token]"]')->attr('value'),
        ];

        if ($active) {
            $payload['active'] = '1';
        }

        $this->client->request('POST', '/administration/comptes/'.$user->getId().'/modifier', ['user' => $payload]);
    }

    /**
     * Fabrique un jeton CSRF valide pour la session en cours.
     *
     * Le gestionnaire de jetons s'appuie sur la session de la requête courante ;
     * hors requête, il n'a rien à quoi se rattacher. On lui en fournit une, le
     * temps de produire le jeton.
     */
    private function csrfToken(string $id): string
    {
        $this->client->request('GET', '/administration/comptes');

        $stack = static::getContainer()->get('request_stack');
        $stack->push($this->client->getRequest());

        try {
            return static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        } finally {
            $stack->pop();
        }
    }

    /** @param list<string> $roles */
    private function givenUser(string $username, array $roles, bool $active = true): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setDisplayName($username)
            ->setAuthSource(AuthSource::Local)
            ->setActive($active)
            ->setRoles($roles);
        $user->setPassword('peu-importe');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * L'état réellement enregistré, lu directement dans la table.
     *
     * Passer par le dépôt rendrait l'entité déjà présente en mémoire, que le
     * formulaire a modifiée même lorsque l'enregistrement a été refusé : le
     * test constaterait alors le changement qu'il cherche justement à
     * empêcher. C'est ce qui a d'abord fait croire à un garde-fou inopérant.
     *
     * @return array{roles: list<string>, active: bool}
     */
    private function stored(User $user): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT roles, active FROM app_user WHERE id = ?',
            [$user->getId()],
        );

        return [
            'roles' => json_decode((string) $row['roles'], true, 8, \JSON_THROW_ON_ERROR),
            'active' => (bool) $row['active'],
        ];
    }
}
