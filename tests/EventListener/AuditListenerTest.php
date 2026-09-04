<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\AuditLog;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\AuthSource;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le journal doit se remplir tout seul.
 *
 * C'est la raison d'etre de l'ecouteur Doctrine : un journal qu'il faut penser
 * a alimenter finira par etre incomplet, et un journal incomplet ne prouve
 * rien. Ces tests verifient donc qu'une ecriture ordinaire — sans aucun appel
 * explicite au journal — laisse bien une trace.
 */
final class AuditListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuditLogRepository $logs;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->logs = self::getContainer()->get(AuditLogRepository::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testCreatingAnEntityIsRecordedWithItsIdentifier(): void
    {
        $organization = $this->persistOrganization('AUD1');

        $entries = $this->logs->forEntity('Organization', (string) $organization->getId());

        self::assertCount(1, $entries);
        self::assertSame(AuditAction::Create, $entries[0]->getAction());
        self::assertSame('Organisation auditée', $entries[0]->getLabel());
        // L'identifiant n'existe qu'apres l'ecriture : le verifier prouve que la
        // ligne de journal est bien posee apres le flush, pas avant.
        self::assertSame((string) $organization->getId(), $entries[0]->getEntityId());
    }

    public function testUpdateRecordsWhatChangedAndNothingElse(): void
    {
        $organization = $this->persistOrganization('AUD2');

        $organization->setName('Nom révisé');
        $this->em->flush();

        $entries = $this->logs->forEntity('Organization', (string) $organization->getId());
        $update = $entries[0];

        self::assertSame(AuditAction::Update, $update->getAction());
        self::assertSame(['name' => ['Organisation auditée', 'Nom révisé']], $update->getChanges());
    }

    /** Un horodatage qui bouge seul ne merite pas une ligne de journal. */
    public function testTimestampOnlyChangesAreNotRecorded(): void
    {
        $organization = $this->persistOrganization('AUD3');
        $before = \count($this->logs->forEntity('Organization', (string) $organization->getId()));

        $organization->touch();
        $this->em->flush();

        self::assertCount($before, $this->logs->forEntity('Organization', (string) $organization->getId()));
    }

    public function testDeletionIsRecordedWithTheLabelItHadWhileAlive(): void
    {
        $organization = $this->persistOrganization('AUD4');
        $id = (string) $organization->getId();

        $this->em->remove($organization);
        $this->em->flush();

        $entries = $this->logs->forEntity('Organization', $id);

        self::assertSame(AuditAction::Delete, $entries[0]->getAction());
        self::assertSame('Organisation auditée', $entries[0]->getLabel());
    }

    /** Un hachage de mot de passe n'a rien a faire dans un journal. */
    public function testPasswordIsNeverWrittenToTheJournal(): void
    {
        $user = (new User())
            ->setUsername('audite.'.random_int(1000, 9999))
            ->setAuthSource(AuthSource::Local);
        $user->setPassword('$argon2id$avant');
        $this->em->persist($user);
        $this->em->flush();

        $user->setPassword('$argon2id$apres');
        $this->em->flush();

        $entries = $this->logs->forEntity('User', (string) $user->getId());
        $changes = $entries[0]->getChanges() ?? [];

        self::assertArrayHasKey('password', $changes);
        self::assertSame(['(masqué)', '(masqué)'], $changes['password']);
        self::assertStringNotContainsString('argon2id', json_encode($changes, \JSON_THROW_ON_ERROR));
    }

    /** Sans utilisateur authentifie, l'acteur est le systeme — pas une valeur vide. */
    public function testActorFallsBackToSystem(): void
    {
        $organization = $this->persistOrganization('AUD5');

        $entries = $this->logs->forEntity('Organization', (string) $organization->getId());

        self::assertSame('système', $entries[0]->getActorUsername());
    }

    /** Le journal ne se journalise pas lui-meme : ce serait sans fin. */
    public function testTheJournalDoesNotRecordItself(): void
    {
        $this->persistOrganization('AUD6');

        self::assertCount(0, $this->logs->findBy(['entityClass' => 'AuditLog']));
        self::assertNotEmpty($this->logs->findBy([]));
        self::assertContainsOnlyInstancesOf(AuditLog::class, $this->logs->findBy([]));
    }

    private function persistOrganization(string $code): Organization
    {
        $organization = (new Organization())->setCode($code)->setName('Organisation auditée');
        $this->em->persist($organization);
        $this->em->flush();

        return $organization;
    }
}
