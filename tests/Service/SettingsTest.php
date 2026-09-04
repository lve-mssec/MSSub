<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Setting;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les paramètres d'exploitation, sur une base réelle.
 *
 * L'ordre de priorité — base, puis environnement, puis défaut — est ce qui
 * permet à une installation neuve de fonctionner sans saisie, et à un
 * administrateur de reprendre la main depuis l'écran. Il mérite d'être verrouillé.
 */
final class SettingsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Settings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->settings = self::getContainer()->get(Settings::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testDatabaseValueWinsOverEnvironment(): void
    {
        $this->settings->set('test.serveur', 'depuis-la-base');
        $this->settings->flush();

        self::assertSame('depuis-la-base', $this->settings->get('test.serveur', 'depuis-l-environnement'));
        self::assertTrue($this->settings->isOverridden('test.serveur'));
    }

    public function testEnvironmentIsUsedWhenNothingIsStored(): void
    {
        self::assertSame('depuis-l-environnement', $this->settings->get('test.absent', 'depuis-l-environnement'));
        self::assertSame('par-defaut', $this->settings->get('test.absent', null, 'par-defaut'));
        self::assertNull($this->settings->get('test.absent'));
        self::assertFalse($this->settings->isOverridden('test.absent'));
    }

    /** Vider un champ à l'écran doit rendre la main à l'environnement. */
    public function testEmptyValueRemovesTheOverride(): void
    {
        $this->settings->set('test.serveur', 'depuis-la-base');
        $this->settings->flush();

        $this->settings->set('test.serveur', '');
        $this->settings->flush();

        self::assertSame('depuis-l-environnement', $this->settings->get('test.serveur', 'depuis-l-environnement'));
        self::assertFalse($this->settings->isOverridden('test.serveur'));
    }

    public function testSecretIsNotReadableInTheTable(): void
    {
        $this->settings->set('test.secret', 'mot-de-passe-annuaire', secret: true);
        $this->settings->flush();

        $stored = $this->em->getRepository(Setting::class)->find('test.secret');

        self::assertNotNull($stored);
        self::assertTrue($stored->isSecret());
        self::assertNotSame('mot-de-passe-annuaire', $stored->getValue());
        self::assertStringNotContainsString('mot-de-passe', (string) $stored->getValue());
        // Mais l'application, elle, doit le relire.
        self::assertSame('mot-de-passe-annuaire', $this->settings->get('test.secret'));
    }

    /** Un secret illisible retombe sur l'environnement plutôt que de servir du faux. */
    public function testUnreadableSecretFallsBackInsteadOfLying(): void
    {
        $this->settings->set('test.secret', 'valeur', secret: true);
        $this->settings->flush();

        $stored = $this->em->getRepository(Setting::class)->find('test.secret');
        $stored->setValue(base64_encode('n-importe-quoi-qui-ne-dechiffre-pas'));
        $this->em->flush();

        $fresh = self::getContainer()->get(Settings::class);
        self::assertSame('repli', $fresh->get('test.secret', 'repli'));
    }

    public function testBooleanReading(): void
    {
        foreach (['1', 'true', 'oui', 'on', 'YES'] as $vrai) {
            $this->settings->set('test.drapeau', $vrai);
            $this->settings->flush();
            self::assertTrue($this->settings->getBool('test.drapeau'), $vrai.' doit valoir vrai');
        }

        foreach (['0', 'false', 'non', 'nawak'] as $faux) {
            $this->settings->set('test.drapeau', $faux);
            $this->settings->flush();
            self::assertFalse($this->settings->getBool('test.drapeau'), $faux.' doit valoir faux');
        }

        $this->settings->set('test.drapeau', '');
        $this->settings->flush();
        self::assertTrue($this->settings->getBool('test.drapeau', true), 'Sans valeur, l\'environnement décide.');
    }
}
