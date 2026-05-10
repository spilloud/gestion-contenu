<?php

namespace App\Controller;

use App\Entity\Format;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\FormatRepository;
use App\Repository\StatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/rapports')]
class ReportsController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly ContentRepository $contentRepository,
        private readonly FormatRepository $formatRepository,
        private readonly StatusRepository $statusRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_reports_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('reports/index.html.twig', [
            'clients' => $this->clientRepository->findAllOrderedByClientName(),
        ]);
    }

    #[Route('/montages', name: 'app_reports_editing_status', methods: ['GET'])]
    public function editingStatus(Request $request): Response
    {
        $clientId = $request->query->getInt('client_id');
        if ($clientId <= 0) {
            $this->addFlash('error', 'Choisis un client pour générer un rapport.');
            return $this->redirectToRoute('app_reports_index');
        }

        $client = $this->clientRepository->find($clientId);
        if ($client === null) {
            $this->addFlash('error', 'Client introuvable.');
            return $this->redirectToRoute('app_reports_index');
        }

        [$start, $end, $periodLabel] = $this->resolvePeriod($request);

        $videoFormat = $this->findVideoFormat();
        $items = $this->contentRepository->findByFilters(
            [$client->getId()],
            null,
            [$videoFormat->getId()],
            $start,
            $end,
        );

        $generatedAt = new \DateTimeImmutable();
        $generatedBy = $this->getUser();
        $generatedByLabel = '—';
        if ($generatedBy instanceof \App\Entity\User) {
            $generatedByLabel = trim((string) ($generatedBy->getName() ?? '')) ?: $generatedBy->getUserIdentifier();
        } elseif ($generatedBy !== null) {
            $generatedByLabel = $generatedBy->getUserIdentifier();
        }

        // Colonnes simples (oui/non) pour un point de situation externe.
        // Elles sont basées sur les statuts actuels de l'app.
        $stages = [
            // Montage = "fait" dès qu'on n'est plus en dérush/montage à faire.
            ['key' => 'montage', 'label' => 'Montage', 'doneIfNotIn' => ['Brouillon (Dérush)', 'Montage à faire']],
            // Sous-titres = "fait" si on n'est pas dans l'étape sous-titres (ou avant). On reste simple.
            ['key' => 'subtitles', 'label' => 'Sous-titres', 'doneIfNotIn' => ['Brouillon (Dérush)', 'Montage à faire', 'Sous-titres à valider']],
            // Terminé = statut final (on couvre plusieurs libellés possibles).
            ['key' => 'done', 'label' => 'Terminé', 'doneIfIn' => ['Terminé', 'Publié', 'Livré', 'Validé', 'OK']],
        ];

        return $this->render('reports/editing_status.html.twig', [
            'client' => $client,
            'periodLabel' => $periodLabel,
            'periodStart' => $start,
            'periodEnd' => $end,
            'generatedAt' => $generatedAt,
            'generatedBy' => $generatedByLabel,
            'stages' => $stages,
            'items' => $items,
        ]);
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: string}
     */
    private function resolvePeriod(Request $request): array
    {
        $range = trim($request->query->getString('range', 'all'));
        $today = new \DateTimeImmutable('today');

        if ($range === '1m') {
            return [$today->modify('-1 month'), $today->modify('+1 day')->modify('-1 second'), 'Dernier mois'];
        }
        if ($range === '3m') {
            return [$today->modify('-3 months'), $today->modify('+1 day')->modify('-1 second'), '3 derniers mois'];
        }

        if ($range === 'custom') {
            $from = trim($request->query->getString('from'));
            $to = trim($request->query->getString('to'));
            $start = $from !== '' ? $this->safeDate($from, '00:00:00') : null;
            $end = $to !== '' ? $this->safeDate($to, '23:59:59') : null;
            $label = 'Période personnalisée';
            if ($start && $end) {
                $label = 'Du '.$start->format('d.m.Y').' au '.$end->format('d.m.Y');
            } elseif ($start) {
                $label = 'Depuis le '.$start->format('d.m.Y');
            } elseif ($end) {
                $label = 'Jusqu’au '.$end->format('d.m.Y');
            }
            return [$start, $end, $label];
        }

        return [null, null, 'Toutes les dates'];
    }

    private function safeDate(string $ymd, string $time): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($ymd.' '.$time);
        } catch (\Throwable) {
            return null;
        }
    }

    private function findVideoFormat(): Format
    {
        foreach ($this->formatRepository->findAllOrdered() as $format) {
            $name = mb_strtolower(trim((string) $format->getName()));
            if ($name === 'vidéo' || $name === 'video') {
                return $format;
            }
        }

        // fallback: create if missing (safe for fresh DBs)
        $format = new Format();
        $format->setName('vidéo');
        $format->setSortOrder(999);
        $this->entityManager->persist($format);
        $this->entityManager->flush();

        return $format;
    }
}

