<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Status;
use App\Entity\User;
use App\Repository\CalendarEventRepository;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Service\YearPlanningGridBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/agenda')]
final class ClientAgendaController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly ContentRepository $contentRepository,
        private readonly CalendarEventRepository $calendarEventRepository,
        private readonly YearPlanningGridBuilder $yearPlanningGridBuilder,
    ) {
    }

    #[Route('', name: 'app_client_agenda_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isClientAccount()) {
            throw $this->createAccessDeniedException();
        }

        $clients = $user->getClientAccesses()->toArray();
        usort($clients, static fn (Client $a, Client $b): int => strcasecmp((string) $a->getName(), (string) $b->getName()));

        if (\count($clients) === 1) {
            $month = (int) date('n');
            $year = (int) date('Y');

            return $this->redirectToRoute('app_client_agenda_month', [
                'id' => $clients[0]->getId(),
                'year' => $year,
                'month' => $month,
            ]);
        }

        return $this->render('client_portal/home.html.twig', [
            'clients' => $clients,
        ]);
    }

    #[Route('/{id}/mois/{year}/{month}', name: 'app_client_agenda_month', requirements: ['id' => '\d+', 'year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['GET'])]
    public function month(Client $client, int $year, int $month): Response
    {
        $this->denyAccessUnlessClientHasAccess($client);

        $month = max(1, min(12, $month));
        $yearStart = (int) date('Y') - 5;
        $yearEnd = (int) date('Y') + 5;
        if ($year < $yearStart || $year > $yearEnd) {
            throw $this->createNotFoundException('Année invalide.');
        }

        $monthStart = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $gridStart = $monthStart->modify('monday this week');
        $gridEnd = $gridStart->modify('+41 days');

        $calendarContents = $this->contentRepository->findByFilters(
            [$client->getId()],
            null,
            null,
            $gridStart,
            $gridEnd
        );
        $calendarEvents = $this->calendarEventRepository->findForCalendarRange(
            $gridStart,
            $gridEnd,
            null,
            $client->getId()
        );

        $prev = $monthStart->modify('-1 month');
        $next = $monthStart->modify('+1 month');

        return $this->render('client_portal/month.html.twig', [
            'client' => $client,
            'calendarContents' => $calendarContents,
            'calendarEvents' => $calendarEvents,
            'calendarMonthStart' => $monthStart,
            'prevMonth' => $prev,
            'nextMonth' => $next,
            'todayDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'calendarYear' => $year,
            'calendarMonth' => $month,
            'statuses' => [], // lecture seule : pas d'édition statut
        ]);
    }

    #[Route('/{id}/annee/{year}', name: 'app_client_agenda_year', requirements: ['id' => '\d+', 'year' => '\d{4}'], methods: ['GET'])]
    public function year(Client $client, int $year): Response
    {
        $this->denyAccessUnlessClientHasAccess($client);

        $currentYear = (int) date('Y');
        if ($year < 2000 || $year > $currentYear + 5) {
            throw $this->createNotFoundException('Année invalide.');
        }

        $yearStart = new \DateTimeImmutable(sprintf('%d-01-01', $year));
        $yearEnd = new \DateTimeImmutable(sprintf('%d-12-31', $year));

        $contents = $this->contentRepository->findByClientForYearPlanning($client, $yearStart, $yearEnd);
        $events = $this->calendarEventRepository->findForCalendarRange($yearStart, $yearEnd, null, $client->getId());
        $grid = $this->yearPlanningGridBuilder->build($year, $contents, $events);

        return $this->render('client_portal/year.html.twig', [
            'client' => $client,
            'year' => $year,
            'prevYear' => $year - 1,
            'nextYear' => $year + 1,
            'grid' => $grid,
            'formatLegend' => YearPlanningGridBuilder::getFormatLegend(),
        ]);
    }

    private function denyAccessUnlessClientHasAccess(Client $client): void
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isClientAccount()) {
            throw $this->createAccessDeniedException();
        }
        if (!$user->getClientAccesses()->contains($client)) {
            throw $this->createAccessDeniedException();
        }
    }
}

