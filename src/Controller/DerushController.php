<?php

namespace App\Controller;

use App\Entity\Content;
use App\Entity\Format;
use App\Entity\Status;
use App\Repository\ClientRepository;
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
        private readonly FormatRepository $formatRepository,
        private readonly StatusRepository $statusRepository,
        private readonly AsanaService $asanaService,
        private readonly ContentWorkflowService $contentWorkflowService,
    ) {
    }

    #[Route('', name: 'app_derush_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('derush', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('app_derush_index');
            }

            $rows = $request->request->all('videos');
            $created = 0;
            $createdContents = [];

            $videoFormat = $this->findVideoFormat();
            $initialStatus = $this->findInitialVideoStatus();

            foreach ($rows as $row) {
                $title = trim((string) ($row['title'] ?? ''));
                $clientId = (int) ($row['client_id'] ?? 0);
                if ($title === '' || $clientId <= 0) {
                    continue;
                }
                $client = $this->clientRepository->find($clientId);
                if ($client === null) {
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
                    ->setStatus($initialStatus)
                    ->setVideoHasSubtitles(($row['has_subtitles'] ?? '') === '1')
                    ->setVideoRushesUrl(trim((string) ($row['rushes_url'] ?? '')) ?: null);

                // Auto-assign monteur depuis le client si défini.
                if ($client->getEditor() !== null) {
                    $content->setVideoEditor($client->getEditor());
                }

                $this->entityManager->persist($content);
                $createdContents[] = $content;
                $created++;
            }

            if ($created > 0) {
                $this->entityManager->flush();

                foreach ($createdContents as $content) {
                    $this->contentWorkflowService->logCreation($content);
                }
                $this->entityManager->flush();

                // Création des tâches Asana (best-effort).
                $fallbackAssignee = getenv('ASANA_FALLBACK_ASSIGNEE_GID');
                $asanaCreated = 0;
                foreach ($createdContents as $content) {
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
                $this->addFlash('success', sprintf('%d vidéo(s) créée(s) en brouillon.', $created));
                if ($asanaCreated > 0) {
                    $this->addFlash('success', sprintf('%d tâche(s) Asana créée(s).', $asanaCreated));
                } elseif ($this->asanaService->isEnabled()) {
                    $this->addFlash('error', 'Aucune tâche Asana créée (mapping client→projet ou assignee manquant).');
                }
                return $this->redirectToRoute('app_calendar', ['formats' => [$videoFormat->getId()]]);
            }

            $this->addFlash('error', 'Aucune vidéo créée (vérifie titre + client).');
        }

        return $this->render('derush/index.html.twig', [
            'clients' => $this->clientRepository->findAllOrderedByClientName(),
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
}

