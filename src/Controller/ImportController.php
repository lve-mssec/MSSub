<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use App\Service\Import\PlanImporter;
use App\Service\Import\RowReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Import d'un plan d'adressage existant.
 *
 * La simulation et l'import réel passent par la même requête : le fichier n'est
 * donc jamais stocké entre deux étapes, ce qui évite d'avoir à gérer un dépôt
 * temporaire, sa purge et les fichiers orphelins. L'opérateur téléverse deux
 * fois, ce qui est un moindre mal.
 */
#[IsGranted('ROLE_OPERATOR')]
final class ImportController extends AbstractController
{
    #[Route('/import', name: 'app_import', methods: ['GET', 'POST'])]
    public function import(
        Request $request,
        OrganizationRepository $organizations,
        RowReader $reader,
        PlanImporter $importer,
    ): Response {
        $all = $organizations->findBy([], ['name' => 'ASC']);
        $report = null;
        $error = null;
        $kind = $request->request->get('type', 'reseaux');
        $dryRun = !$request->request->has('appliquer');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            $file = $request->files->get('fichier');
            $organization = $this->pick($all, $request->request->get('organisation'));

            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $error = 'Aucun fichier reçu, ou téléversement interrompu.';
            } elseif (null === $organization) {
                $error = 'Choisissez une organisation.';
            } else {
                try {
                    $rows = $reader->read($file->getPathname(), $file->getClientOriginalName());

                    $report = 'adresses' === $kind
                        ? $importer->importAddresses($rows, $organization, $dryRun)
                        : $importer->importSubnets($rows, $organization, $dryRun);

                    if (!$dryRun && !$report->hasErrors()) {
                        $this->addFlash('success', \sprintf(
                            '%d créé(s), %d mis à jour, %d inchangé(s).',
                            $report->getCreated(),
                            $report->getUpdated(),
                            $report->getUnchanged(),
                        ));
                    } elseif (!$dryRun) {
                        $this->addFlash('error', "Aucune écriture : le fichier comporte des erreurs.");
                    }
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage();
                } catch (\Throwable $e) {
                    // Un tableur mal formé peut faire échouer la lecture de
                    // mille façons ; l'opérateur a besoin d'un message, pas
                    // d'une page d'erreur.
                    $error = 'Fichier illisible : '.$e->getMessage();
                }
            }
        }

        return $this->render('import/index.html.twig', [
            'nav' => 'import',
            'organizations' => $all,
            'report' => $report,
            'error' => $error,
            'kind' => $kind,
            'dryRun' => $dryRun,
        ]);
    }

    /**
     * @param list<Organization> $all
     */
    private function pick(array $all, mixed $requested): ?Organization
    {
        if (null !== $requested && '' !== $requested) {
            foreach ($all as $organization) {
                if ($organization->getId() === (int) $requested) {
                    return $organization;
                }
            }
        }

        return 1 === \count($all) ? $all[0] : null;
    }
}
