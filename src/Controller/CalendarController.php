<?php

namespace App\Controller;

use App\Entity\Content;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\FormatRepository;
use App\Repository\StatusRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/calendrier')]
class CalendarController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly ContentRepository $contentRepository,
        private readonly StatusRepository $statusRepository,
        private readonly FormatRepository $formatRepository,
    ) {
    }

    #[Route('', name: 'app_calendar', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $view = $request->query->get('view', 'calendar');
        $clientIds = $request->query->all('clients') ?: null;
        $statusIds = $request->query->all('statuses') ?: null;
        $formatIds = $request->query->all('formats') ?: null;

        $month = $request->query->getInt('month', (int) date('n'));
        $year = $request->query->getInt('year', (int) date('Y'));

        $monthStart = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $monthEnd = $monthStart->modify('last day of this month');

        $rangeStart = $monthStart;
        $rangeEnd = $monthEnd;
        if ($view === 'calendar') {
            $gridStart = $monthStart->modify('monday this week');
            $rangeStart = $gridStart;
            $rangeEnd = $gridStart->modify('+41 days');
        }

        $contents = $this->contentRepository->findByFilters(
            $clientIds,
            $statusIds,
            $formatIds,
            $rangeStart,
            $rangeEnd
        );

        return $this->render('calendar/index.html.twig', [
            'contents' => $contents,
            'clients' => $this->clientRepository->findAllOrderedByClientName(),
            'statuses' => $this->statusRepository->findAllOrdered(),
            'formats' => $this->formatRepository->findAllOrdered(),
            'selectedClientIds' => $clientIds ?? [],
            'selectedStatusIds' => $statusIds ?? [],
            'selectedFormatIds' => $formatIds ?? [],
            'view' => $view,
            'month' => $month,
            'year' => $year,
            'monthStart' => $monthStart,
            'monthEnd' => $monthEnd,
        ]);
    }
}
