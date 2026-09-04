<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AuditController extends AbstractController
{
    private const PER_PAGE = 50;

    #[Route('/journal', name: 'app_audit')]
    #[IsGranted('ROLE_OPERATOR')]
    public function index(Request $request, AuditLogRepository $logs): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $action = trim((string) $request->query->get('action', ''));
        $actor = trim((string) $request->query->get('acteur', ''));

        $result = $logs->page($page, self::PER_PAGE, $action ?: null, $actor ?: null);

        return $this->render('audit/index.html.twig', [
            'nav' => 'audit',
            'entries' => $result['entries'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'action' => $action,
            'actor' => $actor,
            'actions' => AuditAction::cases(),
        ]);
    }
}
