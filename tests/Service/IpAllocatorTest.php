<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\IpAllocator;
use App\Service\IpTools;
use PHPUnit\Framework\TestCase;

final class IpAllocatorTest extends TestCase
{
    private IpAllocator $allocator;

    protected function setUp(): void
    {
        $this->allocator = new IpAllocator(new IpTools());
    }

    /** En IPv4, ni l'adresse de reseau ni celle de diffusion ne s'attribuent. */
    public function testUsableRangeExcludesNetworkAndBroadcast(): void
    {
        self::assertSame(['10.0.0.1', '10.0.0.254'], $this->allocator->usableRange('10.0.0.0', 24));
        self::assertSame(['172.16.5.9', '172.16.5.10'], $this->allocator->usableRange('172.16.5.8', 30));
    }

    /** RFC 3021 : sur un /31, les deux adresses servent, sinon il n'en resterait aucune. */
    public function testSlashThirtyOneKeepsBothAddresses(): void
    {
        self::assertSame(['172.16.5.8', '172.16.5.9'], $this->allocator->usableRange('172.16.5.8', 31));
    }

    public function testSlashThirtyTwoIsASingleHost(): void
    {
        self::assertSame(['8.8.8.8', '8.8.8.8'], $this->allocator->usableRange('8.8.8.8', 32));
    }

    /** IPv6 n'a pas de diffusion : tout le prefixe est offert. */
    public function testIpv6KeepsTheWholePrefix(): void
    {
        self::assertSame(
            ['2001:db8::', '2001:db8::ffff:ffff:ffff:ffff'],
            $this->allocator->usableRange('2001:db8::', 64),
        );
    }

    public function testFirstFreeAddressSkipsTakenOnes(): void
    {
        self::assertSame('10.0.0.1', $this->allocator->findFirstFreeAddress('10.0.0.0', 24, []));
        self::assertSame(
            '10.0.0.3',
            $this->allocator->findFirstFreeAddress('10.0.0.0', 24, ['10.0.0.1', '10.0.0.2']),
        );
    }

    public function testTakenListNeedNotBeSorted(): void
    {
        self::assertSame(
            ['10.0.0.4', '10.0.0.6'],
            $this->allocator->findFreeAddresses('10.0.0.0', 24, ['10.0.0.5', '10.0.0.1', '10.0.0.3', '10.0.0.2'], 2),
        );
    }

    public function testDuplicatesInTakenListDoNotShiftTheResult(): void
    {
        self::assertSame(
            '10.0.0.2',
            $this->allocator->findFirstFreeAddress('10.0.0.0', 24, ['10.0.0.1', '10.0.0.1']),
        );
    }

    public function testFullSubnetYieldsNothing(): void
    {
        // Un /30 n'offre que deux adresses : .9 et .10.
        self::assertSame([], $this->allocator->findFreeAddresses('172.16.5.8', 30, ['172.16.5.9', '172.16.5.10']));
    }

    public function testStopsAtTheBroadcastBoundary(): void
    {
        $free = $this->allocator->findFreeAddresses('10.0.0.252', 30, [], 10);

        // .252 est le reseau, .255 la diffusion : deux adresses seulement.
        self::assertSame(['10.0.0.253', '10.0.0.254'], $free);
    }

    /**
     * Chercher dans un /16 ne doit pas couter plus cher que dans un /30 : le
     * cout suit le nombre d'adresses documentees, pas la taille du reseau.
     *
     * Le resultat attendu est .255 et non .1.0 : dans un /16, 10.0.0.255 est
     * une adresse d'hote ordinaire. Seule la derniere du prefixe — 10.0.255.255
     * — est la diffusion. C'est la confusion classique du decoupage en /24.
     */
    public function testLargeSubnetStaysCheapAndKnowsWhereBroadcastReallyIs(): void
    {
        $taken = [];
        for ($i = 1; $i <= 254; ++$i) {
            $taken[] = "10.0.0.{$i}";
        }

        self::assertSame(['10.0.0.255', '10.0.1.0'], $this->allocator->findFreeAddresses('10.0.0.0', 16, $taken, 2));
        self::assertSame(['10.0.255.253', '10.0.255.254'], $this->allocator->findFreeAddresses('10.0.255.252', 30, [], 5));
    }

    public function testIpv6AllocationStartsAtTheSubnetRouterAddress(): void
    {
        self::assertSame('2001:db8::', $this->allocator->findFirstFreeAddress('2001:db8::', 64, []));
        self::assertSame(
            '2001:db8::1',
            $this->allocator->findFirstFreeAddress('2001:db8::', 64, ['2001:db8::']),
        );
    }

    public function testUsage(): void
    {
        $usage = $this->allocator->usage('10.0.0.0', 24, 127);

        self::assertSame(127, $usage['used']);
        self::assertSame('254', $usage['usable']);
        self::assertSame(50.0, $usage['percent']);
    }

    /** Un /64 depasse tout entier PHP : le total reste juste, le pourcentage s'efface. */
    public function testUsageOnHugePrefixStaysHonest(): void
    {
        $usage = $this->allocator->usage('2001:db8::', 64, 12);

        self::assertSame('18446744073709551616', $usage['usable']);
        self::assertSame(0.0, $usage['percent']);
    }

    public function testZeroLimitReturnsNothing(): void
    {
        self::assertSame([], $this->allocator->findFreeAddresses('10.0.0.0', 24, [], 0));
    }
}
