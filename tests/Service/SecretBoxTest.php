<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $box = new SecretBox('secret-applicatif');

        self::assertSame('mot-de-passe-annuaire', $box->decrypt($box->encrypt('mot-de-passe-annuaire')));
    }

    /** Deux chiffrements du même texte doivent différer : sinon on lit les répétitions. */
    public function testSameValueEncryptsDifferentlyEachTime(): void
    {
        $box = new SecretBox('secret-applicatif');

        self::assertNotSame($box->encrypt('identique'), $box->encrypt('identique'));
    }

    /** Une valeur modifiée en base doit être rejetée, pas déchiffrée à moitié. */
    public function testTamperedValueIsRejected(): void
    {
        $box = new SecretBox('secret-applicatif');
        $cipher = $box->encrypt('valeur');

        $raw = base64_decode($cipher, true);
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 0x01);

        self::assertNull($box->decrypt(base64_encode($raw)));
    }

    /** Changer APP_SECRET rend les secrets illisibles : c'est documenté, pas silencieux. */
    public function testAnotherKeyCannotRead(): void
    {
        $cipher = (new SecretBox('premier-secret'))->encrypt('valeur');

        self::assertNull((new SecretBox('second-secret'))->decrypt($cipher));
    }

    public function testGarbageIsRejected(): void
    {
        $box = new SecretBox('secret-applicatif');

        self::assertNull($box->decrypt('pas du base64 !'));
        self::assertNull($box->decrypt(''));
        self::assertNull($box->decrypt(base64_encode('trop-court')));
    }

    public function testEmptyStringSurvivesTheRoundTrip(): void
    {
        $box = new SecretBox('secret-applicatif');

        self::assertSame('', $box->decrypt($box->encrypt('')));
    }
}
