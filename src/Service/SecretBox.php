<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement des secrets stockés en base.
 *
 * La clé dérive de APP_SECRET : elle n'a donc pas à être gérée séparément, mais
 * il faut le savoir — changer APP_SECRET rend les secrets déjà enregistrés
 * illisibles, et il faudra les ressaisir. C'est un compromis assumé face à une
 * gestion de clés dédiée, disproportionnée pour un mot de passe de compte de
 * service et un secret client.
 *
 * Le chiffrement authentifié (XSalsa20-Poly1305) garantit qu'une valeur
 * modifiée en base est rejetée plutôt que déchiffrée en silence.
 */
final class SecretBox
{
    private readonly string $key;

    public function __construct(
        #[Autowire('%env(APP_SECRET)%')]
        string $appSecret,
    ) {
        // generichash produit les 32 octets exigés, quelle que soit la longueur
        // du secret applicatif.
        $this->key = sodium_crypto_generichash($appSecret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /** Renvoie null si la valeur est illisible — clé changée, ou ligne altérée. */
    public function decrypt(string $cipher): ?string
    {
        $raw = base64_decode($cipher, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($payload, $nonce, $this->key);

        return false === $plain ? null : $plain;
    }
}
