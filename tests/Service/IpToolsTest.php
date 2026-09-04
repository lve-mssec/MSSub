<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\IpVersion;
use App\Service\IpTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpToolsTest extends TestCase
{
    private IpTools $tools;

    protected function setUp(): void
    {
        $this->tools = new IpTools();
    }

    #[DataProvider('provideNetworks')]
    public function testNetworkAndLastAddress(string $ip, int $prefix, string $network, string $last): void
    {
        self::assertSame($network, $this->tools->networkAddress($ip, $prefix));
        self::assertSame($last, $this->tools->lastAddress($ip, $prefix));
    }

    /** @return iterable<string, array{string, int, string, string}> */
    public static function provideNetworks(): iterable
    {
        yield '/24 classique' => ['10.20.30.40', 24, '10.20.30.0', '10.20.30.255'];
        yield '/16' => ['10.20.30.40', 16, '10.20.0.0', '10.20.255.255'];
        // Prefixe non aligne sur un octet : c'est la que les implementations naives cassent.
        yield '/26 troisieme quart' => ['192.168.1.130', 26, '192.168.1.128', '192.168.1.191'];
        yield '/30 liaison' => ['172.16.5.9', 30, '172.16.5.8', '172.16.5.11'];
        yield '/31 point a point' => ['172.16.5.9', 31, '172.16.5.8', '172.16.5.9'];
        yield '/32 hote seul' => ['8.8.8.8', 32, '8.8.8.8', '8.8.8.8'];
        yield '/0 tout l espace' => ['1.2.3.4', 0, '0.0.0.0', '255.255.255.255'];
        yield 'IPv6 /64' => ['2001:db8:1:2::5', 64, '2001:db8:1:2::', '2001:db8:1:2:ffff:ffff:ffff:ffff'];
        yield 'IPv6 /48' => ['2001:db8:1:2::5', 48, '2001:db8:1::', '2001:db8:1:ffff:ffff:ffff:ffff:ffff'];
    }

    public function testParseCidrDerivesEverything(): void
    {
        $parsed = $this->tools->parseCidr('10.20.30.40/26');

        self::assertSame('10.20.30.0', $parsed['network']);
        self::assertSame('10.20.30.63', $parsed['last']);
        self::assertSame(26, $parsed['prefix']);
        self::assertSame(IpVersion::V4, $parsed['version']);
    }

    public function testParseCidrRejectsPrefixTooLargeForVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tools->parseCidr('10.0.0.0/33');
    }

    public function testParseCidrRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tools->parseCidr('pas-une-adresse/24');
    }

    /**
     * L'ordre binaire doit etre l'ordre numerique — c'est tout l'interet du
     * VARBINARY(16) face a un VARCHAR, ou '10.0.0.9' passerait apres '10.0.0.10'.
     */
    public function testComparisonIsNumericNotLexicographic(): void
    {
        self::assertSame(-1, $this->tools->compare('10.0.0.9', '10.0.0.10'));
        self::assertSame(1, $this->tools->compare('10.0.0.10', '10.0.0.9'));
        self::assertSame(0, $this->tools->compare('10.0.0.1', '10.0.0.1'));

        $packed = array_map($this->tools->pack(...), ['10.0.0.10', '10.0.0.9', '10.0.0.100']);
        sort($packed);
        self::assertSame(
            ['10.0.0.9', '10.0.0.10', '10.0.0.100'],
            array_map($this->tools->unpack(...), $packed),
        );
    }

    public function testComparingAcrossVersionsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tools->compare('10.0.0.1', '2001:db8::1');
    }

    public function testContains(): void
    {
        self::assertTrue($this->tools->contains('10.20.30.0', 24, '10.20.30.255'));
        self::assertTrue($this->tools->contains('10.20.30.0', 24, '10.20.30.0'));
        self::assertFalse($this->tools->contains('10.20.30.0', 24, '10.20.31.0'));
        self::assertTrue($this->tools->contains('2001:db8::', 32, '2001:db8:ffff::1'));
    }

    public function testNextCrossesByteBoundaries(): void
    {
        self::assertSame('10.0.0.2', $this->tools->next('10.0.0.1'));
        self::assertSame('10.0.1.0', $this->tools->next('10.0.0.255'));
        self::assertSame('10.1.0.0', $this->tools->next('10.0.255.255'));
        // Bout de l'espace : il n'y a pas d'adresse suivante.
        self::assertNull($this->tools->next('255.255.255.255'));
    }

    public function testSizeOfStaysExactBeyondIntegerRange(): void
    {
        self::assertSame('256', $this->tools->sizeOf(24, IpVersion::V4));
        self::assertSame('1', $this->tools->sizeOf(32, IpVersion::V4));
        self::assertSame('4294967296', $this->tools->sizeOf(0, IpVersion::V4));
        // Un /64 depasse ce qu'un entier PHP peut porter : le resultat reste juste.
        self::assertSame('18446744073709551616', $this->tools->sizeOf(64, IpVersion::V6));
    }

    public function testVersionDetection(): void
    {
        self::assertSame(IpVersion::V4, $this->tools->version('10.0.0.1'));
        self::assertSame(IpVersion::V6, $this->tools->version('2001:db8::1'));
    }

    public function testInvalidAddressIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tools->pack('10.0.0.999');
    }
}
