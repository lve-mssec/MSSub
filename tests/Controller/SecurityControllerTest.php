<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\AuthSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le parcours de connexion, joue comme un navigateur le ferait.
 *
 * Ce test soumet reellement le formulaire plutot que d'appeler loginUser() :
 * c'est la seule facon de verifier que le jeton CSRF rendu dans la page est
 * exploitable. Symfony 7.4 propose par defaut un jeton « sans etat » dont la
 * valeur est calculee en JavaScript ; sur une application sans script, la page
 * de connexion serait servie sans erreur mais refuserait toute soumission.
 */
final class SecurityControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Sans cela, le noyau redemarre entre deux requetes et ouvre une autre
        // connexion : les donnees posees dans la transaction ci-dessous seraient
        // invisibles depuis la requete HTTP simulee.
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->givenUser('agent.test', 'un-mot-de-passe-solide');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('http://localhost/connexion');
    }

    public function testLoginPageIsPubliclyReachable(): void
    {
        $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="username"]');
        self::assertSelectorExists('input[name="password"]');
    }

    /**
     * Le champ CSRF doit porter un vrai jeton, pas un marqueur.
     *
     * Le mode « sans etat » de Symfony rend la valeur litterale « csrf-token »,
     * que du JavaScript remplace ensuite dans le navigateur. Sur une
     * application sans script, la page de connexion s'affiche normalement mais
     * refuse toute soumission. Aucun parcours simule ne le detecte — le client
     * de test renvoie le marqueur et la validation le laisse passer — d'ou ce
     * controle direct sur ce qui est rendu.
     */
    public function testTheRenderedCsrfTokenIsARealTokenNotAPlaceholder(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $value = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        self::assertNotSame(
            'csrf-token',
            $value,
            'Le jeton CSRF est un marqueur destine a etre remplace par du JavaScript : '
            .'la connexion serait impossible dans un navigateur sans script.',
        );
        self::assertNotEmpty($value);
    }

    /** Le jeton rendu doit etre utilisable tel quel, sans JavaScript. */
    public function testSubmittingTheRenderedFormLogsIn(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'username' => 'agent.test',
            'password' => 'un-mot-de-passe-solide',
        ]));

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testWrongPasswordIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'username' => 'agent.test',
            'password' => 'pas-le-bon',
        ]));

        $this->client->followRedirect();
        self::assertSelectorExists('.alert');
    }

    /** Un compte desactive doit rester dehors, meme avec le bon mot de passe. */
    public function testDeactivatedAccountCannotLogIn(): void
    {
        $this->givenUser('agent.parti', 'un-mot-de-passe-solide', active: false);

        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'username' => 'agent.parti',
            'password' => 'un-mot-de-passe-solide',
        ]));

        $this->client->followRedirect();
        self::assertSelectorExists('.alert');
    }

    private function givenUser(string $username, string $password, bool $active = true): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setUsername($username)
            ->setDisplayName($username)
            ->setAuthSource(AuthSource::Local)
            ->setActive($active)
            ->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();
    }
}
