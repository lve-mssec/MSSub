<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\Subnet;
use App\Enum\AuditAction;
use App\Repository\OrganizationRepository;
use App\Service\AuditRecorder;
use App\Service\Export\CsvExporter;
use App\Service\Export\DhcpConfigExporter;
use App\Service\Export\DnsZoneExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ExportController extends AbstractController
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/export', name: 'app_export')]
    public function index(OrganizationRepository $organizations): Response
    {
        return $this->render('export/index.html.twig', [
            'nav' => 'export',
            'organizations' => $organizations->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/export/{id}/reseaux.csv', name: 'app_export_subnets', requirements: ['id' => '\d+'])]
    public function subnets(Organization $organization, CsvExporter $exporter): Response
    {
        return $this->download(
            $exporter->subnets($organization),
            \sprintf('mssub-reseaux-%s.csv', $this->slug($organization)),
            'text/csv; charset=UTF-8',
            $organization,
            'Export CSV des réseaux',
        );
    }

    #[Route('/export/{id}/adresses.csv', name: 'app_export_addresses', requirements: ['id' => '\d+'])]
    public function addresses(Organization $organization, CsvExporter $exporter): Response
    {
        return $this->download(
            $exporter->addresses($organization),
            \sprintf('mssub-adresses-%s.csv', $this->slug($organization)),
            'text/csv; charset=UTF-8',
            $organization,
            'Export CSV des adresses',
        );
    }

    #[Route('/export/{id}/dns.zone', name: 'app_export_dns', requirements: ['id' => '\d+'])]
    public function dns(Request $request, Organization $organization, DnsZoneExporter $exporter): Response
    {
        $domain = $this->domain($request);

        return $this->download(
            $exporter->forward($organization, $domain),
            \sprintf('mssub-%s.zone', $this->slug($organization)),
            'text/plain; charset=UTF-8',
            $organization,
            \sprintf('Export de zone DNS directe (%s)', $domain),
        );
    }

    #[Route('/export/reseau/{id}/inverse.zone', name: 'app_export_dns_reverse', requirements: ['id' => '\d+'])]
    public function reverse(Request $request, Subnet $subnet, DnsZoneExporter $exporter): Response
    {
        $domain = $this->domain($request);

        return $this->download(
            $exporter->reverse($subnet, $domain),
            \sprintf('mssub-%s-inverse.zone', str_replace(['.', '/'], '-', $subnet->getCidr())),
            'text/plain; charset=UTF-8',
            $subnet->getOrganization(),
            \sprintf('Export de zone DNS inversée pour %s', $subnet->getCidr()),
        );
    }

    #[Route('/export/{id}/dhcpd.conf', name: 'app_export_dhcp', requirements: ['id' => '\d+'])]
    public function dhcp(Request $request, Organization $organization, DhcpConfigExporter $exporter): Response
    {
        $domain = $this->domain($request);

        return $this->download(
            $exporter->export($organization, $domain),
            \sprintf('mssub-dhcpd-%s.conf', $this->slug($organization)),
            'text/plain; charset=UTF-8',
            $organization,
            'Export de configuration DHCP',
        );
    }

    /**
     * Un export sort de l'application : il laisse donc une trace, au même titre
     * qu'une écriture. Savoir qui a extrait le plan d'adressage, et quand, fait
     * partie de ce qu'un journal doit pouvoir répondre.
     */
    private function download(
        string $content,
        string $filename,
        string $contentType,
        ?Organization $organization,
        string $label,
    ): Response {
        $this->audit->record($this->em, AuditAction::Export, $organization, null, $label);
        $this->em->flush();

        $response = new Response($content);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition('attachment', $filename),
        );

        return $response;
    }

    private function domain(Request $request): string
    {
        $domain = trim((string) $request->query->get('domaine', ''));

        return '' === $domain ? 'exemple.local' : $domain;
    }

    private function slug(Organization $organization): string
    {
        return strtolower($organization->getCode());
    }
}
