<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\IpTools;
use App\Service\SubnetAllocator;
use PHPUnit\Framework\TestCase;

final class SubnetAllocatorTest extends TestCase
{
    private SubnetAllocator $allocator;

    protected function setUp(): void
    {
        $this->allocator = new SubnetAllocator(new IpTools());
    }

    public function testEmptyContainerAllocatesFromTheStart(): void
    {
        self::assertSame(
            '10.0.0.0/26',
            $this->allocator->findFirstFreeBlock('10.0.0.0', 24, 26, []),
        );
    }

    public function testSkipsTheFirstOccupiedBlock(): void
    {
        self::assertSame(
            '10.0.0.64/26',
            $this->allocator->findFirstFreeBlock('10.0.0.0', 24, 26, [['10.0.0.0', '10.0.0.63']]),
        );
    }

    public function testFillsHolesBetweenAllocations(): void
    {
        $free = $this->allocator->findFreeBlocks('10.0.0.0', 24, 26, [
            ['10.0.0.0', '10.0.0.63'],
            ['10.0.0.128', '10.0.0.191'],
        ]);

        self::assertSame(['10.0.0.64/26', '10.0.0.192/26'], $free);
    }

    /**
     * Un occupant mal aligne (issu d'un import approximatif) ne doit pas
     * decaler la grille : il consomme le bloc qui le contient, pas plus.
     */
    public function testMisalignedOccupantStillConsumesItsWholeBlock(): void
    {
        self::assertSame(
            ['10.0.0.64/26', '10.0.0.128/26', '10.0.0.192/26'],
            $this->allocator->findFreeBlocks('10.0.0.0', 24, 26, [['10.0.0.10', '10.0.0.20']]),
        );
    }

    public function testUnsortedInputIsHandled(): void
    {
        $free = $this->allocator->findFreeBlocks('10.0.0.0', 24, 26, [
            ['10.0.0.192', '10.0.0.255'],
            ['10.0.0.0', '10.0.0.63'],
        ]);

        self::assertSame(['10.0.0.64/26', '10.0.0.128/26'], $free);
    }

    public function testFullContainerYieldsNothing(): void
    {
        self::assertSame([], $this->allocator->findFreeBlocks('10.0.0.0', 24, 25, [
            ['10.0.0.0', '10.0.0.127'],
            ['10.0.0.128', '10.0.0.255'],
        ]));
        self::assertNull($this->allocator->findFirstFreeBlock('10.0.0.0', 24, 25, [
            ['10.0.0.0', '10.0.0.255'],
        ]));
    }

    /** Un /22 ne rentre pas dans un /24 : la demande est refusee, pas approximee. */
    public function testPrefixShorterThanContainerIsRefused(): void
    {
        self::assertSame([], $this->allocator->findFreeBlocks('10.0.0.0', 24, 22, []));
    }

    public function testContainerSizedBlockFitsExactlyOnce(): void
    {
        self::assertSame(['10.0.0.0/24'], $this->allocator->findFreeBlocks('10.0.0.0', 24, 24, []));
    }

    public function testLimitIsHonoured(): void
    {
        $free = $this->allocator->findFreeBlocks('10.0.0.0', 16, 24, [], 3);

        self::assertSame(['10.0.0.0/24', '10.0.1.0/24', '10.0.2.0/24'], $free);
    }

    /**
     * Parcourir un /16 a la recherche de /30 ne doit pas dependre de sa taille :
     * le curseur saute d'occupant en occupant.
     */
    public function testLargeContainerStaysCheap(): void
    {
        $allocated = [];
        for ($i = 0; $i < 200; ++$i) {
            $allocated[] = ["10.0.{$i}.0", "10.0.{$i}.255"];
        }

        self::assertSame('10.0.200.0/30', $this->allocator->findFirstFreeBlock('10.0.0.0', 16, 30, $allocated));
    }

    public function testLargestFreeBlock(): void
    {
        self::assertSame('10.0.0.0/24', $this->allocator->largestFreeBlock('10.0.0.0', 24, []));
        self::assertSame(
            '10.0.0.128/25',
            $this->allocator->largestFreeBlock('10.0.0.0', 24, [['10.0.0.0', '10.0.0.127']]),
        );
        self::assertNull($this->allocator->largestFreeBlock('10.0.0.0', 24, [['10.0.0.0', '10.0.0.255']]));
    }

    public function testIpv6Allocation(): void
    {
        self::assertSame(
            ['2001:db8:0:1::/64', '2001:db8:0:2::/64'],
            $this->allocator->findFreeBlocks('2001:db8::', 48, 64, [['2001:db8::', '2001:db8::ffff:ffff:ffff:ffff']], 2),
        );
    }

    /** Une plage IPv6 ne doit pas polluer un calcul IPv4, et inversement. */
    public function testMixedFamiliesAreIgnored(): void
    {
        self::assertSame(
            '10.0.0.0/26',
            $this->allocator->findFirstFreeBlock('10.0.0.0', 24, 26, [['2001:db8::', '2001:db8::ffff']]),
        );
    }

    public function testOverlapDetection(): void
    {
        $allocated = [['10.0.0.0', '10.0.0.63']];

        self::assertTrue($this->allocator->overlaps('10.0.0.32', '10.0.0.95', $allocated));
        self::assertTrue($this->allocator->overlaps('10.0.0.0', '10.0.0.63', $allocated));
        self::assertFalse($this->allocator->overlaps('10.0.0.64', '10.0.0.127', $allocated));
    }
}
