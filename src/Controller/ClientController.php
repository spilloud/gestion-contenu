<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\ClientPage;
use App\Entity\Content;
use App\Entity\ContentActionLog;
use App\Service\ContentWorkflowService;
use App\Service\PublishingSettings;
use App\Form\ClientPageType;
use App\Repository\CalendarEventRepository;
use App\Repository\CampaignRepository;
use App\Repository\ContentRepository;
use App\Repository\StatusRepository;
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
        private readonly CampaignRepository $campaignRepository,
        private readonly StatusRepository $statusRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly YearPlanningGridBuilder $yearPlanningGridBuilder,
        private readonly ContentWorkflowService $contentWorkflowService,
        private readonly \App\Service\ContentFormatHelper $contentFormatHelper,
    ) {
    }

    /** Statuts « prêts » listés dans la zone Metricool (vidéo / post). */
    private const METRICOOL_READY_STATUSES = ['Prête à programmer', 'Prêt à publier'];

    /**
     * Raison pour laquelle un contenu prêt ne peut pas partir dans Metricool (null = envoyable).
     */
    private function metricoolBlockReason(Content $content): ?string
    {
        $missing = array_filter([
            trim((string) $content->getVideoFinalUrl()) === '' && trim((string) $content->getVideoThumbnailUrl()) === '' ? 'pas de visuel' : null,
            trim((string) $content->getVideoCaption()) === '' ? 'pas de légende' : null,
            $content->getScheduledDate() !== null && $content->getScheduledDate()->format('Y-m-d') < date('Y-m-d') ? 'date de publication passée' : null,
        ]);

        return $missing === [] ? null : ucfirst(implode(', ', $missing));
    }

    /**
     * Tous les contenus prêts du client, pas encore envoyés dans Metricool (pas de filtre par semaine).
     *
     * @return list<array{content: Content, isVideo: bool, time: string, reason: ?string}>
     */
    private function buildMetricoolRows(Client $client): array
    {
        /** @var list<Content> $contents */
        $contents = $this->contentRepository->createQueryBuilder('c')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->leftJoin('c.format', 'f')->addSelect('f')
            ->andWhere('c.client = :client')
            ->andWhere('s.name IN (:ready)')
            ->andWhere('c.metricoolApprovedAt IS NULL')
            ->setParameter('client', $client)
            ->setParameter('ready', self::METRICOOL_READY_STATUSES)
            ->orderBy('c.scheduledDate', 'ASC')
            ->addOrderBy('c.scheduledTime', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        $defaultTime = PublishingSettings::defaultTimeFor($client);
        $rows = [];
        foreach ($contents as $content) {
            $rows[] = [
                'content' => $content,
                'isVideo' => $this->contentFormatHelper->isVideoContent($content),
                'time' => $content->getScheduledTime()?->format('H:i') ?? $defaultTime,
                'reason' => $this->metricoolBlockReason($content),
            ];
        }

        return $rows;
    }

    /**
     * « Envoyer dans Metricool » : enregistre l'heure et la validation CM. Rien d'autre :
     * c'est Lucy qui lit metricoolApprovedAt dans l'export et pousse dans Metricool.
     */
    #[Route('/{id}/metricool', name: 'app_client_metricool_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approveForMetricool(Client $client, Request $request): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_CM')) {
            throw $this->createAccessDeniedException();
        }

        $redirect = $this->generateUrl('app_client_show', ['id' => $client->getId()]).'#metricool';

        if (!$this->isCsrfTokenValid('metricool'.$client->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide. Rechargez la page.');

            return $this->redirect($redirect);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $request->request->all('ids')))));
        $times = $request->request->all('times');
        if ($ids === []) {
            $this->addFlash('error', 'Aucun contenu coché.');

            return $this->redirect($redirect);
        }

        $now = new \DateTimeImmutable();
        $sent = 0;
        $refused = 0;
        foreach ($this->contentRepository->findBy(['id' => $ids, 'client' => $client]) as $content) {
            $statusName = $content->getStatus()?->getName();
            if (!in_array($statusName, self::METRICOOL_READY_STATUSES, true)
                || $content->getMetricoolApprovedAt() !== null
                || $this->metricoolBlockReason($content) !== null) {
                ++$refused;
                continue;
            }

            $time = (string) ($times[$content->getId()] ?? '');
            if (!in_array($time, PublishingSettings::HOURS, true)) {
                $time = PublishingSettings::defaultTimeFor($client);
            }

            $content->setScheduledTime(new \DateTimeImmutable('1970-01-01 '.$time.':00'));
            $content->setMetricoolApprovedAt($now);
            $content->setUpdatedAt($now);

            $date = $content->getScheduledDate()?->format('d/m/Y') ?? '—';
            $this->contentWorkflowService->logFieldChange(
                $content,
                ContentActionLog::TYPE_METRICOOL_APPROVED,
                'Envoi Metricool demandé',
                sprintf("Publication : %s à %s\nPar : %s", $date, $time, $this->getUser()?->getUserIdentifier() ?? '—'),
                false,
            );
            ++$sent;
        }
        $this->entityManager->flush();

        if ($sent > 0) {
            $this->addFlash('success', sprintf('%d contenu(s) envoyé(s) à Lucy pour Metricool.', $sent));
        }
        if ($refused > 0) {
            $this->addFlash('warning', sprintf('%d contenu(s) ignoré(s) (plus prêt, déjà envoyé, sans visuel / légende ou date passée).', $refused));
        }

        return $this->redirect($redirect);
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
            'metricoolRows' => $this->buildMetricoolRows($client),
            'metricoolHours' => PublishingSettings::HOURS,
            'publishing' => PublishingSettings::forClient($client),
            'publishingNetworks' => PublishingSettings::NETWORKS,
            'youtubePrivacy' => PublishingSettings::YOUTUBE_PRIVACY,
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
            'clientCampaign' => $this->campaignRepository->findPreferredForClient($client),
            'clientCampaignCurrent' => $this->campaignRepository->findCurrentForClient($client),
        ]);
    }

    /**
     * Enregistre la box « Configuration publication » (admin / CM). Lue par Lucy via l'export.
     */
    #[Route('/{id}/configuration-publication', name: 'app_client_publishing_settings', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function savePublishingSettings(Client $client, Request $request): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_CM')) {
            throw $this->createAccessDeniedException();
        }

        $redirect = $this->generateUrl('app_client_show', ['id' => $client->getId()]).'#configuration-publication';
        if (!$this->isCsrfTokenValid('publishing'.$client->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide. Rechargez la page.');

            return $this->redirect($redirect);
        }

        $client->setPublishingSettings(PublishingSettings::normalize($request->request->all('settings')));
        $this->entityManager->flush();
        $this->addFlash('success', 'Configuration de publication enregistrée.');

        return $this->redirect($redirect);
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
