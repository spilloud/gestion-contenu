<?php

namespace App\Controller;

use App\Entity\Content;
use App\Entity\Format;
use App\Entity\Status;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\FormatRepository;
use App\Repository\StatusRepository;
use App\Service\AsanaService;
use App\Service\ContentWorkflowService;
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
        private readonly AsanaService $asanaService,
        private readonly ContentWorkflowService $contentWorkflowService,
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
            $globalRushesUrl = trim($request->request->getString('rushes_url_global')) ?: null;
            $created = 0;
            $plannedMoved = 0;
            $newContents = [];
            $touchedContents = [];

            $videoFormat = $this->findVideoFormat();
            $statusMontageAFaire = $this->findOrCreateVideoStatus('Montage à faire', Status::COLOR_ORANGE, 30);

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

                $this->contentWorkflowService->applyManualStatusChange($content, $statusMontageAFaire, 'derush');
                if ($globalRushesUrl !== null) {
                    $content->setVideoRushesUrl($globalRushesUrl);
                }
                $content->setUpdatedAt(new \DateTimeImmutable());
                $touchedContents[] = $content;
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
                    ->setVideoRushesUrl($globalRushesUrl);

                // Auto-assign monteur et CM depuis le client si définis.
                if ($client->getEditor() !== null) {
                    $content->setVideoEditor($client->getEditor());
                }
                if ($client->getCommunityManager() !== null) {
                    $content->setVideoCommunityManager($client->getCommunityManager());
                }

                $this->entityManager->persist($content);
                $touchedContents[] = $content;
                $newContents[] = $content;
                $created++;
            }

            if ($touchedContents !== []) {
                $this->entityManager->flush();

                foreach ($newContents as $content) {
                    $this->contentWorkflowService->logCreation($content);
                }
                $this->entityManager->flush();

                // Création des tâches Asana (best-effort).
                $fallbackAssignee = getenv('ASANA_FALLBACK_ASSIGNEE_GID');
                $asanaCreated = 0;
                foreach ($touchedContents as $content) {
                    if ($content->getAsanaTaskGid()) {
                        continue;
                    }
                    $url = $this->generateUrl('app_video_show', ['id' => $content->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
                    $gid = $this->asanaService->createTaskForVideo(
                        $content,
                        $url,
                        $fallbackAssignee === false ? null : (string) $fallbackAssignee,
                    );
                    if ($gid) {
                        $content->setAsanaTaskGid($gid);
                        $asanaCreated++;
                    }
                }
                if ($asanaCreated > 0) {
                    $this->entityManager->flush();
                }
                $messages = [];
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
                    $this->addFlash('success', sprintf('%d tâche(s) Asana créée(s).', $asanaCreated));
                } elseif ($this->asanaService->isEnabled()) {
                    $this->addFlash('error', 'Aucune tâche Asana créée (mapping client→projet ou assignee manquant).');
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

