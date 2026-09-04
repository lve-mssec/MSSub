<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Repository\SettingRepository;
use App\Service\SecretBox;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

/**
 * Un magasin de paramètres vide, pour les tests qui portent sur autre chose.
 *
 * Les services d'authentification consultent les paramètres, mais leur logique
 * propre — correspondance des rôles, lecture des revendications — n'en dépend
 * pas. Un magasin vide les fait retomber sur les valeurs passées au
 * constructeur, ce qui garde ces tests unitaires et lisibles.
 */
trait EmptySettingsTrait
{
    private function emptySettings(): Settings
    {
        // Des bouchons et non des simulacres : ces tests ne vérifient rien sur
        // les appels au dépôt, ils ont seulement besoin qu'il ne dise rien.
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('allIndexed')->willReturn([]);

        return new Settings(
            $repository,
            $this->createStub(EntityManagerInterface::class),
            new SecretBox('secret-de-test'),
            new NullLogger(),
        );
    }
}
