<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lecture et écriture des paramètres d'exploitation.
 *
 * Trois niveaux, du plus fort au plus faible : la base, puis la variable
 * d'environnement, puis le défaut du code. Une installation neuve fonctionne
 * donc sans que rien n'ait été saisi, et un paramètre effacé depuis l'écran
 * retombe sur sa valeur d'environnement au lieu de se vider.
 *
 * Les valeurs sont lues une fois par requête : la configuration est consultée à
 * chaque tentative de connexion, et un aller-retour en base par champ serait
 * payé sur le chemin le plus sensible de l'application.
 */
final class Settings
{
    /** @var array<string, Setting>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly SecretBox $secrets,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(string $name, ?string $envValue = null, ?string $default = null): ?string
    {
        $setting = $this->load()[$name] ?? null;

        if (null === $setting || null === $setting->getValue() || '' === $setting->getValue()) {
            return $this->fallback($envValue, $default);
        }

        if (!$setting->isSecret()) {
            return $setting->getValue();
        }

        $plain = $this->secrets->decrypt($setting->getValue());
        if (null === $plain) {
            // Clé applicative changée, ou ligne altérée : mieux vaut retomber
            // sur l'environnement que servir une valeur fausse en silence.
            $this->logger->error('Secret illisible en base ; retour à la configuration d\'environnement.', ['paramètre' => $name]);

            return $this->fallback($envValue, $default);
        }

        return $plain;
    }

    public function getBool(string $name, bool $envValue = false): bool
    {
        $raw = $this->get($name);

        if (null === $raw) {
            return $envValue;
        }

        return \in_array(strtolower($raw), ['1', 'true', 'oui', 'yes', 'on'], true);
    }

    /** Indique si la valeur vient de la base plutôt que de l'environnement. */
    public function isOverridden(string $name): bool
    {
        $setting = $this->load()[$name] ?? null;

        return null !== $setting && null !== $setting->getValue() && '' !== $setting->getValue();
    }

    /**
     * Enregistre une valeur.
     *
     * Une chaîne vide efface le paramètre plutôt que de stocker du vide : c'est
     * ce qui permet de revenir à la configuration d'environnement depuis l'écran.
     */
    public function set(string $name, ?string $value, bool $secret = false): void
    {
        $settings = $this->load();
        $setting = $settings[$name] ?? null;

        if (null === $value || '' === $value) {
            if (null !== $setting) {
                $this->em->remove($setting);
                unset($this->cache[$name]);
            }

            return;
        }

        if (null === $setting) {
            $setting = new Setting($name);
            $this->em->persist($setting);
            $this->cache[$name] = $setting;
        }

        $setting->setSecret($secret)
            ->setValue($secret ? $this->secrets->encrypt($value) : $value);
    }

    public function flush(): void
    {
        $this->em->flush();
        $this->cache = null;
    }

    /** @return array<string, Setting> */
    private function load(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        try {
            return $this->cache = $this->repository->allIndexed();
        } catch (DbalException $e) {
            // Table absente : c'est le cas avant la première migration. Les
            // écrans de connexion doivent rester utilisables.
            $this->logger->warning('Paramètres illisibles ; configuration d\'environnement seule.', ['exception' => $e]);

            return $this->cache = [];
        }
    }

    private function fallback(?string $envValue, ?string $default): ?string
    {
        if (null !== $envValue && '' !== $envValue) {
            return $envValue;
        }

        return $default;
    }
}
