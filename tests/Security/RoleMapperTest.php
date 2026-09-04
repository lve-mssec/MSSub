<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\RoleMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RoleMapperTest extends TestCase
{
    use EmptySettingsTrait;

    private const MAP = [
        'mssub-admins' => 'ROLE_ADMIN',
        'MSSub-Operateurs' => 'ROLE_OPERATOR',
    ];

    public function testGroupsBecomeRoles(): void
    {
        $mapper = new RoleMapper(new NullLogger(), $this->emptySettings(), self::MAP);

        self::assertSame(['ROLE_ADMIN', User::ROLE_USER], $mapper->rolesFor(['mssub-admins']));
    }

    /** Un annuaire ne garantit pas la casse d'un nom de groupe. */
    public function testMatchingIgnoresCase(): void
    {
        $mapper = new RoleMapper(new NullLogger(), $this->emptySettings(), self::MAP);

        self::assertSame(['ROLE_OPERATOR', User::ROLE_USER], $mapper->rolesFor(['MSSUB-OPERATEURS']));
        self::assertSame(['ROLE_ADMIN', User::ROLE_USER], $mapper->rolesFor(['MSSub-Admins']));
    }

    /**
     * Un groupe inconnu ne doit pas fermer la porte : le compte entre en
     * lecture seule. Un mapping incomplet dégrade vers le moindre privilège,
     * il ne provoque pas d'erreur.
     */
    public function testUnknownGroupsFallBackToReadOnly(): void
    {
        $mapper = new RoleMapper(new NullLogger(), $this->emptySettings(), self::MAP);

        self::assertSame([User::ROLE_USER], $mapper->rolesFor(['comptabilite', 'tout-le-monde']));
        self::assertSame([User::ROLE_USER], $mapper->rolesFor([]));
    }

    public function testDuplicateRolesAreCollapsed(): void
    {
        $mapper = new RoleMapper(new NullLogger(), $this->emptySettings(), ['a' => 'ROLE_ADMIN', 'b' => 'ROLE_ADMIN']);

        self::assertSame(['ROLE_ADMIN', User::ROLE_USER], $mapper->rolesFor(['a', 'b']));
    }

    /** Sans correspondance configurée, personne n'obtient de privilège. */
    public function testEmptyMapGrantsNothingExtra(): void
    {
        $mapper = new RoleMapper(new NullLogger(), $this->emptySettings(), []);

        self::assertSame([User::ROLE_USER], $mapper->rolesFor(['mssub-admins']));
    }
}
