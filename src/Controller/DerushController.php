<?php

namespace App\Controller;

use App\Entity\Content;
use App\Entity\Format;
use App\Entity\Status;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\FormatRepository;
use App\Repository\StatusRepository;
use App\Service\ContentWorkflowService;
use App\Service\AsanaBidirectionalSyncService;
use App\Service\DerushCmAsanaTrigger;
use App\Service\VideoAssigneeResolver;
use App\Service\VideoMontageAsanaTrigger;
use App\Service\VideoMontageDueOnResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/derush')]
class DerushController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clientRepository,
        private readonly ContentRepository $contentRepository,
        private readonly FormatRepository $formatRepository,
        private readonly StatusRepository $statusRepository,
        private readonly ContentWorkflowService $contentWorkflowService,
        private readonly VideoAssigneeResolver $videoAssigneeResolver,
        private readonly VideoMontageAsanaTrigger $montageAsanaTrigger,
        private readonly VideoMontageDueOnResolver $montageDueOnResolver,
        private readonly DerushCmAsanaTrigger $derushCmAsanaTrigger,
        private readonly AsanaBidirectionalSyncService $asanaBidirectionalSync,
    ) {
    }

    #[Route('', name: 'app_derush_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $defaultClientId = $request->query->getInt('client') ?: null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('derush', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('app_derush_index');
            }

            $clientId = $request->request->getInt('client_id');
            if ($clientId <= 0) {
                $this->addFlash('error', 'Sélectionne un client.');
                return $this->redirectToRoute('app_derush_index', array_filter(['client' => $defaultClientId]));
            }

            $client = $this->clientRepository->find($clientId);
            if ($client === null) {
                $this->addFlash('error', 'Client introuvable.');
                return $this->redirectToRoute('app_derush_index');
            }

            $rows = $request->request->all('videos');
            $plannedIds = array_values(array_filter(array_map('intval', (array) $request->request->all('planned_ids'))));
            $plannedMontageDue = $request->request->all('planned_montage_due');
            $globalRushesUrl = trim($request->request->getString('rushes_url_global')) ?: null;
            $created = 0;
            $plannedMoved = 0;
            $plannedDatesUpdated = 0;
            $newContents = [];
            $derushedContents = [];
            $touchedContents = [];

            $videoFormat = $this->findVideoFormat();
            $statusMontageAFaire = $this->findOrCreateVideoStatus('Montage à faire', Status::COLOR_ORANGE, 30);

            // 0) Mise à jour des dates de montage souhaitées (vidéos planifiées affichées).
            foreach ($plannedMontageDue as $idStr => $dateStr) {
                $id = (int) $idStr;
                if ($id <= 0) {
                    continue;
                }
                $content = $this->contentRepository->find($id);
                if (!$content instanceof Content) {
                    continue;
                }
                if ($content->getClient()?->getId() !== $client->getId()) {
                    continue;
                }
                if ($content->getFormat()?->getId() !== $videoFormat->getId()) {
                    continue;
                }

                $due = $this->montageDueOnResolver->parseOrDefault(is_string($dateStr) ? $dateStr : null, $content);
                if ($content->getAsanaMontageDueOn()?->format('Y-m-d') !== $due->format('Y-m-d')) {
                    $content->setAsanaMontageDueOn($due);
                    $touchedContents[$id] = $content;
                    ++$plannedDatesUpdated;
                }
            }

            // 1) Vidéos planifiées par les CM : cocher = "dérush fait" => on passe directement à Montage à faire.
            foreach ($plannedIds as $id) {
                $content = $this->contentRepository->find($id);
                if (!$content instanceof Content) {
                    continue;
                }
                if ($content->getClient()?->getId() !== $client->getId()) {
                    continue;
                }
                if ($content->getFormat()?->getId() !== $videoFormat->getId()) {
                    continue;
                }

                $this->videoAssigneeResolver->applyClientTeamDefaultsForForm($content);
                if ($globalRushesUrl !== null) {
                    $content->setVideoRushesUrl($globalRushesUrl);
                }
                $this->contentWorkflowService->applyManualStatusChange($content, $statusMontageAFaire, 'derush');
                $touchedContents[$content->getId()] = $content;
                $derushedContents[] = $content;
                $plannedMoved++;
            }

            // 2) Création de nouvelles vidéos (casier en bas) pour ce client.
            foreach ($rows as $row) {
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                // Planification : on ne demande pas la date au dérush.
                // On met une date par défaut à +14 jours si l'utilisateur ne planifie pas encore.
                $scheduledDate = (new \DateTimeImmutable('today'))->modify('+14 days');

                $content = new Content();
                $content
                    ->setTitle($title)
                    ->setClient($client)
                    ->setScheduledDate($scheduledDate)
                    ->setFormat($videoFormat)
                    ->setStatus($statusMontageAFaire)
                    ->setVideoHasSubtitles(($row['has_subtitles'] ?? '') === '1')
                    ->setVideoRushesUrl($globalRushesUrl)
                    ->setAsanaMontageDueOn(
                        $this->montageDueOnResolver->parseOptional(isset($row['montage_due_on']) ? (string) $row['montage_due_on'] : null)
                            ?? $this->montageDueOnResolver->defaultFromPublication($scheduledDate),
                    );

                $this->videoAssigneeResolver->applyClientTeamDefaultsForForm($content);

                $this->entityManager->persist($content);
                $newContents[] = $content;
                $derushedContents[] = $content;
                $touchedContents['new_'.$created] = $content;
                $created++;
            }

            $touchedList = array_values($touchedContents);

            if ($touchedList !== []) {
                $this->entityManager->flush();

                foreach ($newContents as $content) {
                    $this->contentWorkflowService->logCreation($content);
                }
                if ($newContents !== []) {
                    $this->entityManager->flush();
                }

                $asanaCreated = 0;
                foreach ($touchedList as $content) {
                    if ($content->getStatus()?->getName() === 'Montage à faire'
                        && $this->montageAsanaTrigger->ensureWhenMontageQueued($content, false)) {
                        ++$asanaCreated;
                    }
                }
                if ($asanaCreated > 0 || $plannedDatesUpdated > 0) {
                    $this->entityManager->flush();
                }

                $cmTaskCreated = false;
                if ($derushedContents !== []) {
                    $cmTaskGid = $this->derushCmAsanaTrigger->createFollowUpTask(
                        $client,
                        $derushedContents,
                        $globalRushesUrl,
                    );
                    if ($cmTaskGid !== null) {
                        $this->asanaBidirectionalSync->registerDerushFollowUpTask($client, $derushedContents, $cmTaskGid);
                        $this->entityManager->flush();
                    }
                    $cmTaskCreated = $cmTaskGid !== null;
                }

                $messages = [];
                if ($plannedDatesUpdated > 0) {
                    $messages[] = sprintf('%d date(s) de montage enregistrée(s).', $plannedDatesUpdated);
                }
                if ($plannedMoved > 0) {
                    $messages[] = sprintf('%d vidéo(s) planifiée(s) passée(s) en montage.', $plannedMoved);
                }
                if ($created > 0) {
                    $messages[] = sprintf('%d vidéo(s) créée(s).', $created);
                }
                if ($messages !== []) {
                    $this->addFlash('success', implode(' ', $messages));
                }
                if ($asanaCreated > 0) {
                    $this->addFlash('success', sprintf('%d tâche(s) Asana montage créée(s).', $asanaCreated));
                }
                if ($cmTaskCreated) {
                    $this->addFlash('success', 'Tâche Asana de suivi dérush créée pour la CM.');
                } elseif ($derushedContents !== []
                    && getenv('ASANA_ACCESS_TOKEN') !== false
                    && trim((string) getenv('ASANA_ACCESS_TOKEN')) !== '') {
                    $this->addFlash('warning', 'Tâche Asana suivi CM non créée (projet client ou CM Asana manquant).');
                }
                if ($asanaCreated === 0
                    && getenv('ASANA_ACCESS_TOKEN') !== false
                    && trim((string) getenv('ASANA_ACCESS_TOKEN')) !== '') {
                    $stillMissing = false;
                    foreach ($touchedList as $c) {
                        if ($c->getStatus()?->getName() === 'Montage à faire' && $c->getAsanaTaskGid() === null) {
                            $stillMissing = true;
                            break;
                        }
                    }
                    if ($stillMissing) {
                        $this->addFlash('error', 'Aucune tâche Asana montage (projet client, monteur Asana ou API).');
                    }
                }
                return $this->redirectToRoute('app_calendar', ['formats' => [$videoFormat->getId()]]);
            }

            $this->addFlash('error', 'Aucune vidéo sélectionnée ou créée (vérifie le client, les cases, ou les titres).');
        }

        $clients = $this->clientRepository->findAllOrderedByClientName();
        $selectedClient = null;
        if ($defaultClientId !== null) {
            $selectedClient = $this->clientRepository->find($defaultClientId);
        }

        $videoFormat = $this->findVideoFormat();
        $plannedStatus = $this->statusRepository->findOneByName('Tournage à prévoir');
        $plannedVideos = [];
        if ($selectedClient !== null && $plannedStatus !== null) {
            $plannedVideos = $this->contentRepository->createQueryBuilder('c')
                ->leftJoin('c.client', 'cl')->addSelect('cl')
                ->leftJoin('c.videoEditor', 'e')->addSelect('e')
                ->andWhere('c.client = :client')
                ->andWhere('c.format = :format')
                ->andWhere('c.status = :status')
                ->setParameter('client', $selectedClient)
                ->setParameter('format', $videoFormat)
                ->setParameter('status', $plannedStatus)
                ->orderBy('c.scheduledDate', 'ASC')
                ->addOrderBy('c.id', 'ASC')
                ->getQuery()
                ->getResult();
        }

        return $this->render('derush/index.html.twig', [
            'clients' => $clients,
            'defaultClientId' => $defaultClientId,
            'plannedVideos' => $plannedVideos,
            'montageDueOnResolver' => $this->montageDueOnResolver,
        ]);
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

    private function findInitialVideoStatus(): Status
    {
        foreach ($this->statusRepository->findAllOrdered() as $status) {
            if ($status->getName() === 'Brouillon (Dérush)') {
                return $status;
            }
        }

        // fallback: create if missing (safe for fresh DBs)
        $status = new Status();
        $status->setName('Brouillon (Dérush)');
        $status->setColor(Status::COLOR_GRAY);
        $status->setSortOrder(100);
        $status->setWorkflow(Status::WORKFLOW_VIDEO);
        $this->entityManager->persist($status);
        $this->entityManager->flush();

        return $status;
    }

    private function findOrCreateVideoStatus(string $name, string $color, int $sortOrder): Status
    {
        $existing = $this->statusRepository->findOneByName($name);
        if ($existing !== null) {
            return $existing;
        }

        $status = new Status();
        $status->setName($name);
        $status->setColor($color);
        $status->setSortOrder($sortOrder);
        $status->setWorkflow(Status::WORKFLOW_VIDEO);
        $this->entityManager->persist($status);
        $this->entityManager->flush();

        return $status;
    }
}

