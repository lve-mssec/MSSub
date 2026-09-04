<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * Une adresse IP, vue « 10.0.0.1 » cote PHP et VARBINARY(16) cote base.
 *
 * Le binaire n'est pas une coquetterie : c'est lui qui rend les comparaisons SQL
 * justes. En VARCHAR, '10.0.0.9' > '10.0.0.10', et toute recherche de plage
 * (« quels subnets contiennent cette adresse ? ») devient fausse. En binaire de
 * longueur fixe, l'ordre lexicographique est l'ordre numerique, pour v4 comme v6.
 */
final class IpAddressType extends Type
{
    public const NAME = 'ip_address';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'VARBINARY(16)';
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $packed = @inet_pton((string) $value);
        if (false === $packed) {
            throw ConversionException::conversionFailedFormat((string) $value, self::NAME, 'une adresse IPv4 ou IPv6');
        }

        return $packed;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        // Selon le pilote, une colonne binaire revient en flux ou en chaine.
        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if ('' === $value || false === $value) {
            return null;
        }

        $printable = @inet_ntop($value);

        return false === $printable ? null : $printable;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
