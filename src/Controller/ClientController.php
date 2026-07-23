<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\ClientPage;
use App\Form\ClientPageType;
use App\Repository\CalendarEventRepository;
use App\Repository\ContentRepository;
use App\Repository\StatusRepository;
use App\Service\AsanaBidirectionalSyncService;
use App\Service\YearPlanningGridBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/client')]
class ClientController extends AbstractController
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly CalendarEventRepository $calendarEventRepository,
        private readonly StatusRepository $statusRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly YearPlanningGridBuilder $yearPlanningGridBuilder,
        private readonly AsanaBidirectionalSyncService $asanaBidirectionalSync,
    ) {
    }

    #[Route('/{id}/planning/{year}', name: 'app_client_year_planning', requirements: ['id' => '\d+', 'year' => '\d{4}'], methods: ['GET'])]
    public function yearPlanning(Client $client, int $year): Response
    {
        $currentYear = (int) date('Y');
        if ($year < 2000 || $year > $currentYear + 5) {
            throw $this->createNotFoundException('Année invalide.');
        }

        $yearStart = new \DateTimeImmutable(sprintf('%d-01-01', $year));
        $yearEnd = new \DateTimeImmutable(sprintf('%d-12-31', $year));

        $contents = $this->contentRepository->findByClientForYearPlanning(
            $client,
            $yearStart,
            $yearEnd
        );
        $events = $this->calendarEventRepository->findForCalendarRange(
            $yearStart,
            $yearEnd,
            null,
            $client->getId()
        );

        $grid = $this->yearPlanningGridBuilder->build($year, $contents, $events);

        return $this->render('client/year_planning.html.twig', [
            'client' => $client,
            'year' => $year,
            'prevYear' => $year - 1,
            'nextYear' => $year + 1,
            'grid' => $grid,
            'formatLegend' => YearPlanningGridBuilder::getFormatLegend(),
        ]);
    }

    #[Route('/{id}', name: 'app_client_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Client $client, Request $request): Response
    {
        $showArchives = $request->query->getBoolean('archives');
        $calendarMonth = $request->query->getInt('cmonth', (int) date('n'));
        $calendarYear = $request->query->getInt('cyear', (int) date('Y'));
        $calendarMonthStart = new \DateTimeImmutable(sprintf('%d-%02d-01', $calendarYear, $calendarMonth));
        $calendarMonthEnd = $calendarMonthStart->modify('last day of this month');
        $calendarGridStart = $calendarMonthStart->modify('monday this week');
        $calendarGridEnd = $calendarGridStart->modify('+41 days');

        $clientPage = $client->getClientPage();
        if ($clientPage === null) {
            $clientPage = new ClientPage();
            $clientPage->setClient($client);
            $client->setClientPage($clientPage);
        }

        $form = $this->createForm(ClientPageType::class, $clientPage);
        $form->handleRequest($request);

        if ($request->isMethod('GET')) {
            $synced = $this->asanaBidirectionalSync->syncContentsForClient($client, true);
            if ($synced > 0) {
                $this->addFlash('info', sprintf('%d vidéo(s) synchronisée(s) avec Asana.', $synced));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($clientPage->getTodoItems() as $i => $item) {
                $item->setSortOrder($i);
            }
            $this->entityManager->persist($clientPage);
            $this->entityManager->flush();

            $this->addFlash('success', 'Modifications enregistrées.');

            $redirectParams = ['id' => $client->getId()];
            if ($showArchives) {
                $redirectParams['archives'] = 1;
            }
            $redirectParams['cmonth'] = $calendarMonth;
            $redirectParams['cyear'] = $calendarYear;

            return $this->redirectToRoute('app_client_show', $redirectParams);
        }

        $contents = $this->contentRepository->findByClientAndArchiveState($client, $showArchives);
        $calendarContents = $this->contentRepository->findByFilters(
            [$client->getId()],
            null,
            null,
            $calendarGridStart,
            $calendarGridEnd
        );
        $calendarEvents = $this->calendarEventRepository->findForCalendarRange(
            $calendarGridStart,
            $calendarGridEnd,
            null,
            $client->getId()
        );

        return $this->render('client/show.html.twig', [
            'client' => $client,
            'clientPage' => $clientPage,
            'contents' => $contents,
            'calendarContents' => $calendarContents,
            'calendarEvents' => $calendarEvents,
            'form' => $form,
            'showArchives' => $showArchives,
            'calendarMonth' => $calendarMonth,
            'calendarYear' => $calendarYear,
            'calendarMonthStart' => $calendarMonthStart,
            'statuses' => $this->statusRepository->findSelectableForWorkflow(\App\Entity\Status::WORKFLOW_STANDARD),
            'videoStatuses' => $this->statusRepository->findSelectableForWorkflow(\App\Entity\Status::WORKFLOW_VIDEO),
        ]);
    }

    #[Route('/{id}/todo/toggle/{todoId}', name: 'app_client_todo_toggle', requirements: ['id' => '\d+', 'todoId' => '\d+'], methods: ['POST'])]
    public function toggleTodo(Client $client, int $todoId): Response
    {
        $clientPage = $client->getClientPage();
        if ($clientPage === null) {
            return $this->redirectToRoute('app_client_show', ['id' => $client->getId()]);
        }

        foreach ($clientPage->getTodoItems() as $item) {
            if ($item->getId() === $todoId) {
                $item->setDone(!$item->isDone());
                $this->entityManager->flush();
                break;
            }
        }

        return $this->redirectToRoute('app_client_show', ['id' => $client->getId()]);
    }
}
