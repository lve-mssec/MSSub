<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Organization;
use App\Entity\Site;
use App\Entity\Subnet;
use App\Enum\IpVersion;
use PHPUnit\Framework\TestCase;

/**
 * L'héritage du site le long de la hiérarchie.
 *
 * C'est la règle qui décide de tout le reste : le regroupement de
 * l'arborescence, le contenu d'une fiche de site, et ce qu'un périmètre laisse
 * voir. Un sous-réseau ne déclare presque jamais son site — il le tient du bloc
 * qui le contient — et se limiter au site déclaré viderait ces trois écrans.
 */
final class SubnetSiteTest extends TestCase
{
    public function testOwnSiteWins(): void
    {
        $paris = $this->site('Paris');
        $lyon = $this->site('Lyon');

        $block = $this->subnet('10.10.0.0', 16, $paris);
        $child = $this->subnet('10.10.10.0', 24, $lyon, $block);

        self::assertSame($lyon, $child->getEffectiveSite());
        self::assertFalse($child->inheritsSite());
    }

    public function testSiteIsInheritedFromTheNearestBlock(): void
    {
        $paris = $this->site('Paris');

        $root = $this->subnet('10.0.0.0', 8);
        $block = $this->subnet('10.10.0.0', 16, $paris, $root);
        $leaf = $this->subnet('10.10.10.0', 24, null, $block);

        self::assertSame($paris, $leaf->getEffectiveSite());
        self::assertTrue($leaf->inheritsSite());
    }

    /** Le plus proche ancêtre l'emporte : un site plus haut ne doit pas primer. */
    public function testNearestAncestorWins(): void
    {
        $siege = $this->site('Siège');
        $annexe = $this->site('Annexe');

        $root = $this->subnet('10.0.0.0', 8, $siege);
        $middle = $this->subnet('10.10.0.0', 16, $annexe, $root);
        $leaf = $this->subnet('10.10.10.0', 24, null, $middle);

        self::assertSame($annexe, $leaf->getEffectiveSite());
    }

    public function testNoSiteAnywhereYieldsNull(): void
    {
        $root = $this->subnet('192.168.0.0', 16);
        $leaf = $this->subnet('192.168.1.0', 24, null, $root);

        self::assertNull($leaf->getEffectiveSite());
        self::assertFalse($leaf->inheritsSite(), 'Sans site nulle part, il n\'y a rien à hériter.');
    }

    /**
     * Une hiérarchie circulaire ne doit pas figer l'affichage.
     *
     * Le cas ne devrait pas exister, mais une reprise de données maladroite
     * peut le produire, et la page des plans d'adressage est justement celle
     * où on irait le constater.
     */
    public function testACycleIsSurvivable(): void
    {
        $a = $this->subnet('10.0.0.0', 8);
        $b = $this->subnet('10.10.0.0', 16, null, $a);
        $a->setParent($b);

        self::assertNull($b->getEffectiveSite());
    }

    private function site(string $name): Site
    {
        return (new Site())
            ->setOrganization((new Organization())->setCode('ORG')->setName('Organisation'))
            ->setCode(strtoupper(substr($name, 0, 3)))
            ->setName($name);
    }

    private function subnet(string $network, int $prefix, ?Site $site = null, ?Subnet $parent = null): Subnet
    {
        return (new Subnet())
            ->setVersion(IpVersion::V4)
            ->setNetworkAddress($network)
            ->setPrefixLength($prefix)
            ->setSite($site)
            ->setParent($parent);
    }
}
