<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\Admin\LdapSettingsType;
use App\Form\Admin\OidcSettingsType;
use App\Security\LdapDirectory;
use App\Security\OidcClient;
use App\Security\RoleMapper;
use App\Service\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Configuration des sources d'authentification.
 *
 * Les secrets ne sont jamais renvoyés au navigateur : un champ laissé vide
 * conserve la valeur enregistrée. Rendre un mot de passe dans une page, même
 * masquée, revient à le confier à l'historique du navigateur et à tout ce qui
 * lit le HTML.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/administration')]
final class SettingsController extends AbstractController
{
    /** Champs dont la valeur est chiffrée en base et jamais réaffichée. */
    private const SECRETS = ['ldap.search_password', 'oidc.client_secret'];

    public function __construct(private readonly Settings $settings)
    {
    }

    #[Route('/annuaire', name: 'app_admin_ldap')]
    public function ldap(Request $request, LdapDirectory $directory): Response
    {
        $form = $this->createForm(LdapSettingsType::class, $this->read(LdapDirectory::KEYS, 'ldap.'));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->write($form->getData(), 'ldap.');
            $this->addFlash('success', 'Configuration de l\'annuaire enregistrée.');

            return $this->redirectToRoute('app_admin_ldap');
        }

        // Le test se fait sur la configuration enregistrée, pas sur celle
        // affichée : c'est celle-là qui servira aux connexions.
        $test = $request->query->has('tester') ? $directory->testConnection() : null;

        return $this->render('admin/ldap.html.twig', [
            'nav' => 'admin',
            'form' => $form,
            'test' => $test,
            'active' => $directory->isEnabled(),
        ]);
    }

    #[Route('/sso', name: 'app_admin_oidc')]
    public function oidc(Request $request, OidcClient $client): Response
    {
        $form = $this->createForm(OidcSettingsType::class, $this->read(OidcClient::KEYS, 'oidc.'));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->write($form->getData(), 'oidc.');
            $this->addFlash('success', 'Configuration du fournisseur d\'identité enregistrée.');

            return $this->redirectToRoute('app_admin_oidc');
        }

        return $this->render('admin/oidc.html.twig', [
            'nav' => 'admin',
            'form' => $form,
            'active' => $client->isEnabled(),
            // L'URL de retour doit être déclarée à l'identique côté fournisseur :
            // l'afficher évite la faute de frappe qui coûte une demi-journée.
            'redirect_uri' => $this->generateUrl('app_oidc_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    /**
     * Correspondance des groupes vers les rôles.
     *
     * Saisie en « groupe = ROLE_X », une ligne par groupe, plutôt qu'en JSON :
     * l'écran s'adresse à un administrateur réseau, pas à un développeur.
     */
    #[Route('/roles', name: 'app_admin_roles')]
    public function roles(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('roles', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            ['map' => $map, 'rejected' => $rejected] = $this->parseMap((string) $request->request->get('map', ''));

            $this->settings->set(RoleMapper::KEY, [] === $map ? null : json_encode($map, \JSON_THROW_ON_ERROR));
            $this->settings->flush();

            $this->addFlash('success', \sprintf('%d correspondance(s) enregistrée(s).', \count($map)));

            if ([] !== $rejected) {
                $this->addFlash('error', \sprintf(
                    'Rôle inconnu, ligne(s) ignorée(s) : %s.',
                    implode(', ', $rejected),
                ));
            }

            return $this->redirectToRoute('app_admin_roles');
        }

        return $this->render('admin/roles.html.twig', [
            'nav' => 'admin',
            'map' => $this->formatMap(),
            'overridden' => $this->settings->isOverridden(RoleMapper::KEY),
        ]);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private function read(array $keys, string $prefix): array
    {
        $data = [];

        foreach ($keys as $key) {
            $field = substr($key, \strlen($prefix));

            $data[$field] = match (true) {
                'enabled' === $field => $this->settings->getBool($key),
                \in_array($key, self::SECRETS, true) => null,
                default => $this->settings->get($key),
            };
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function write(array $data, string $prefix): void
    {
        foreach ($data as $field => $value) {
            $key = $prefix.$field;

            if (\in_array($key, self::SECRETS, true)) {
                // Champ vide : on ne touche pas au secret déjà enregistré.
                if (null !== $value && '' !== $value) {
                    $this->settings->set($key, (string) $value, secret: true);
                }
                continue;
            }

            $this->settings->set($key, 'enabled' === $field ? ($value ? '1' : '0') : (null === $value ? null : (string) $value));
        }

        $this->settings->flush();
    }

    /**
     * Analyse la saisie « groupe = ROLE ».
     *
     * Les rôles sont validés contre ceux que l'application connaît. Accepter
     * n'importe quel libellé écrirait un rôle inexistant sans rien dire, et le
     * groupe concerné se retrouverait en lecture seule sans que personne
     * comprenne pourquoi.
     *
     * @return array{map: array<string, string>, rejected: list<string>}
     */
    private function parseMap(string $raw): array
    {
        $known = [User::ROLE_USER, User::ROLE_OPERATOR, User::ROLE_ADMIN];
        $map = [];
        $rejected = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (2 !== \count($parts)) {
                continue;
            }

            $group = trim($parts[0]);
            $role = strtoupper(trim($parts[1]));
            if ('' === $group || '' === $role) {
                continue;
            }

            if (!str_starts_with($role, 'ROLE_')) {
                $role = 'ROLE_'.$role;
            }

            if (!\in_array($role, $known, true)) {
                $rejected[] = $group;
                continue;
            }

            $map[$group] = $role;
        }

        return ['map' => $map, 'rejected' => $rejected];
    }

    private function formatMap(): string
    {
        $raw = $this->settings->get(RoleMapper::KEY);
        if (null === $raw) {
            return '';
        }

        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        $lines = [];
        foreach ((array) $decoded as $group => $role) {
            $lines[] = \sprintf('%s = %s', $group, $role);
        }

        return implode("\n", $lines);
    }
}
