<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\DeviceRepository;
use App\Repository\OrganizationRepository;
use App\Repository\SiteRepository;
use App\Repository\UserRepository;
use App\Repository\VlanRepository;
use App\Security\LdapDirectory;
use App\Security\OidcClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    #[Route('/administration', name: 'app_admin')]
    public function index(
        OrganizationRepository $organizations,
        SiteRepository $sites,
        VlanRepository $vlans,
        DeviceRepository $devices,
        UserRepository $users,
        LdapDirectory $directory,
        OidcClient $oidc,
    ): Response {
        return $this->render('admin/index.html.twig', [
            'nav' => 'admin',
            'counts' => [
                'organizations' => $organizations->count([]),
                'sites' => $sites->count([]),
                'vlans' => $vlans->count([]),
                'devices' => $devices->count([]),
                'users' => $users->count([]),
            ],
            'ldap' => $directory->isEnabled(),
            'oidc' => $oidc->isEnabled(),
        ]);
    }
}
