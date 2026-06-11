<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Content;
use App\Entity\Format;
use App\Entity\ShootingRequest;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\FormatRepository;
use App\Repository\ShootingRequestRepository;
use App\Repository\StatusRepository;
use App\Repository\UserRepository;
use App\Service\AsanaService;
use App\Service\RichTextSanitizer;
use App\Service\ShootingRequestDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/tournage')]
class ShootingRequestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShootingRequestRepository $shootingRequestRepository,
        private readonly ClientRepository $clientRepository,
        private readonly ContentRepository $contentRepository,
        private readonly FormatRepository $formatRepository,
        private readonly StatusRepository $statusRepository,
        private readonly UserRepository $userRepository,
        private readonly AsanaService $asanaService,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly ShootingRequestDeletionService $shootingRequestDeletionService,
    ) {
    }

    #[Route('', name: 'app_shooting_request_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('tournage/index.html.twig', [
            'requests' => $this->shootingRequestRepository->findAllForList(),
        ]);
    }

    #[Route('/nouvelle', name: 'app_shooting_request_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $defaultClientId = $request->query->getInt('client') ?: null;

        if ($request->isMethod('POST')) {
            return $this->handleCreate($request, $defaultClientId);
        }

        return $this->renderNewForm($defaultClientId);
    }

    #[Route('/{id}/supprimer', name: 'app_shooting_request_delete', requirements: ['id' => '\d+'], methods: ['POST'], priority: 10)]
    public function delete(int $id, Request $request): Response
    {
        $shootingRequest = $this->shootingRequestRepository->findOneForShow($id);
        if ($shootingRequest === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('shooting_delete_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_shooting_request_index');
        }

        $clientName = $shootingRequest->getClient()?->getName() ?? '—';
        $hadAsanaTask = trim((string) ($shootingRequest->getAsanaTaskGid() ?? '')) !== '';
        $result = $this->shootingRequestDeletionService->delete($shootingRequest);

        if (!$result['ok']) {
            $this->addFlash('error', $result['message'] ?? 'Suppression impossible.');

            return $this->redirectToRoute('app_shooting_request_index');
        }

        $this->addFlash('success', sprintf('Demande de tournage « %s » supprimée.', $clientName));
        if ($result['videosReset'] > 0) {
            $this->addFlash('success', sprintf('%d vidéo(s) remise(s) en planification (Tournage à prévoir).', $result['videosReset']));
        }
        if ($hadAsanaTask && !$result['asanaDeleted']) {
            $this->addFlash('warning', 'La tâche Asana n\'a pas pu être supprimée (vérifiez dans Asana).');
        }

        return $this->redirectToRoute('app_shooting_request_index');
    }

    #[Route('/{id}/modifier', name: 'app_shooting_request_update_details', requirements: ['id' => '\d+'], methods: ['POST'], priority: 10)]
    public function updateDetails(int $id, Request $request): Response
    {
        $shootingRequest = $this->shootingRequestRepository->findOneForShow($id);
        if ($shootingRequest === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('shooting_details_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_shooting_request_show', ['id' => $id]);
        }

        $dateStr = trim($request->request->getString('shooting_date'));
        if ($dateStr === '') {
            $this->addFlash('error', 'Indiquez la date du tournage.');

            return $this->redirectToRoute('app_shooting_request_show', ['id' => $id, 'edit_infos' => 1]);
        }

        try {
            $shootingRequest->setShootingDate(new \DateTime($dateStr));
        } catch (\Throwable) {
            $this->addFlash('error', 'Date du tournage invalide.');

            return $this->redirectToRoute('app_shooting_request_show', ['id' => $id, 'edit_infos' => 1]);
        }

        $assignedId = $request->request->getInt('assigned_to_id');
        $assignedTo = $assignedId > 0 ? $this->userRepository->find($assignedId) : null;
        if (!$assignedTo instanceof User) {
            $this->addFlash('error', 'Sélectionnez le vidéaste.');

            return $this->redirectToRoute('app_shooting_request_show', ['id' => $id, 'edit_infos' => 1]);
        }

        $shootingRequest
            ->setAssignedTo($assignedTo)
            ->setLocation(trim($request->request->getString('location')) ?: null)
            ->setDescription(trim($request->request->getString('description')) ?: null);

        $this->entityManager->flush();
        $this->addFlash('success', 'Informations du tournage mises à jour.');

        return $this->redirectToRoute('app_shooting_request_show', ['id' => $id]);
    }

    #[Route('/{id}/videos', name: 'app_shooting_request_update_videos', requirements: ['id' => '\d+'], methods: ['POST'], priority: 10)]
    public function updateVideos(int $id, Request $request): Response
    {
        $shootingRequest = $this->shootingRequestRepository->findOneForShow($id);
        if ($shootingRequest === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('shooting_videos_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_shooting_request_show', ['id' => $id]);
        }

        $client = $shootingRequest->getClient();
        if ($client === null) {
            $this->addFlash('error', 'Client introuvable.');

            return $this->redirectToRoute('app_shooting_request_show', ['id' => $id]);
        }

        $videoIds = array_values(array_unique(array_filter(array_map('intval', (array) $request->request->all('video_ids')))));
        if ($videoIds === []) {
            $this->addFlash('error', 'Sélectionnez au moins une vidéo.');

            return $this->redirectToRoute('app_shooting_request_show', ['id' => $id, 'edit_videos' => 1]);
        }

        $allowedIds = [];
        foreach ($this->findSelectableVideosForRequest($shootingRequest) as $video) {
            $allowedIds[$video->getId()] = true;
        }

        $validVideos = [];
        foreach ($videoIds as $videoId) {
            if (!isset($allowedIds[$videoId])) {
                continue;
            }
            $content = $this->contentRepository->find($videoId);
            if ($content instanceof Content && $content->getClient()?->getId() === $client->getId()) {
                $validVideos[] = $content;
            }
        }

        if ($validVideos === []) {
            $this->addFlash('error', 'Aucune vidéo valide sélectionnée.');

            return $this->redirectToRoute('app_shooting_request_show', ['id' => $id, 'edit_videos' => 1]);
        }

        foreach ($shootingRequest->getVideos()->toArray() as $existing) {
            $shootingRequest->removeVideo($existing);
        }
        foreach ($validVideos as $video) {
            $shootingRequest->addVideo($video);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'Liste des vidéos mise à jour.');

        return $this->redirectToRoute('app_shooting_request_show', ['id' => $id]);
    }

    #[Route('/{id}/infos-videaste', name: 'app_shooting_request_videographer_notes', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateVideographerNotes(int $id, Request $request): Response
    {
        $shootingRequest = $this->shootingRequestRepository->findOneForShow($id);
        if ($shootingRequest === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('shooting_videographer_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_shooting_request_show', ['id' => $id]);
        }

        $shootingRequest->setVideographerNotes(
            $this->richTextSanitizer->sanitize($request->request->getString('videographer_notes')),
        );
        $this->entityManager->flush();

        $this->addFlash('success', 'Infos vidéaste enregistrées.');

        return $this->redirectToRoute('app_shooting_request_show', ['id' => $id]);
    }

    #[Route('/{id}', name: 'app_shooting_request_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request): Response
    {
        $shootingRequest = $this->shootingRequestRepository->findOneForShow($id);
        if ($shootingRequest === null) {
            throw $this->createNotFoundException();
        }

        $currentVideoIds = [];
        foreach ($shootingRequest->getVideos() as $video) {
            if ($video->getId() !== null) {
                $currentVideoIds[$video->getId()] = true;
            }
        }

        return $this->render('tournage/show.html.twig', [
            'request' => $shootingRequest,
            'employees' => $this->userRepository->findEmployeesOrdered(),
            'selectableVideos' => $this->findSelectableVideosForRequest($shootingRequest),
            'currentVideoIds' => $currentVideoIds,
            'editInfos' => $request->query->getBoolean('edit_infos'),
            'editVideos' => $request->query->getBoolean('edit_videos'),
        ]);
    }

    private function handleCreate(Request $request, ?int $defaultClientId): Response
    {
        if (!$this->isCsrfTokenValid('shooting_request', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_shooting_request_new', array_filter(['client' => $defaultClientId]));
        }

        $clientId = $request->request->getInt('client_id');
        $client = $clientId > 0 ? $this->clientRepository->find($clientId) : null;
        if ($client === null) {
            $this->addFlash('error', 'Sélectionnez un client.');

            return $this->redirectToRoute('app_shooting_request_new');
        }

        $videoIds = array_values(array_filter(array_map('intval', (array) $request->request->all('video_ids'))));
        if ($videoIds === []) {
            $this->addFlash('error', 'Cochez au moins une vidéo à tourner.');

            return $this->redirectToRoute('app_shooting_request_new', ['client' => $client->getId()]);
        }

        $dateStr = trim($request->request->getString('shooting_date'));
        if ($dateStr === '') {
            $this->addFlash('error', 'Indiquez la date du tournage.');

            return $this->redirectToRoute('app_shooting_request_new', ['client' => $client->getId()]);
        }

        try {
            $shootingDate = new \DateTime($dateStr);
        } catch (\Throwable) {
            $this->addFlash('error', 'Date du tournage invalide.');

            return $this->redirectToRoute('app_shooting_request_new', ['client' => $client->getId()]);
        }

        $assignedId = $request->request->getInt('assigned_to_id');
        $assignedTo = $assignedId > 0 ? $this->userRepository->find($assignedId) : null;
        if (!$assignedTo instanceof User) {
            $this->addFlash('error', 'Sélectionnez le vidéaste.');

            return $this->redirectToRoute('app_shooting_request_new', ['client' => $client->getId()]);
        }

        $videoFormat = $this->findVideoFormat();
        $plannedStatus = $this->statusRepository->findOneByName('Tournage à prévoir');
        $validVideos = [];

        foreach ($videoIds as $videoId) {
            $content = $this->contentRepository->find($videoId);
            if (!$content instanceof Content) {
                continue;
            }
            if ($content->getClient()?->getId() !== $client->getId()) {
                continue;
            }
            if ($videoFormat !== null && $content->getFormat()?->getId() !== $videoFormat->getId()) {
                continue;
            }
            if ($plannedStatus !== null && $content->getStatus()?->getId() !== $plannedStatus->getId()) {
                continue;
            }
            $validVideos[] = $content;
        }

        if ($validVideos === []) {
            $this->addFlash('error', 'Aucune vidéo planifiée valide sélectionnée.');

            return $this->redirectToRoute('app_shooting_request_new', ['client' => $client->getId()]);
        }

        $shootingRequest = new ShootingRequest();
        $shootingRequest
            ->setClient($client)
            ->setShootingDate($shootingDate)
            ->setDescription(trim($request->request->getString('description')) ?: null)
            ->setVideographerNotes($this->richTextSanitizer->sanitize($request->request->getString('videographer_notes')))
            ->setLocation(trim($request->request->getString('location')) ?: null)
            ->setAssignedTo($assignedTo);

        $user = $this->getUser();
        if ($user instanceof User) {
            $shootingRequest->setCreatedBy($user);
        }

        foreach ($validVideos as $video) {
            $shootingRequest->addVideo($video);
        }

        $this->entityManager->persist($shootingRequest);
        $this->entityManager->flush();

        $requestUrl = $this->generateUrl(
            'app_shooting_request_show',
            ['id' => $shootingRequest->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $fallbackAssignee = trim((string) (getenv('ASANA_FALLBACK_ASSIGNEE_GID') ?: ''));
        $asanaGid = $this->asanaService->createShootingRequestTask(
            $shootingRequest,
            $requestUrl,
            $fallbackAssignee !== '' ? $fallbackAssignee : null,
        );

        if ($asanaGid !== null) {
            $shootingRequest->setAsanaTaskGid($asanaGid);
            $this->entityManager->flush();
            $this->addFlash('success', 'Demande de tournage enregistrée — tâche Asana créée.');
        } else {
            $this->addFlash('success', 'Demande de tournage enregistrée.');
            if ($this->asanaService->isEnabled()) {
                $this->addFlash('warning', 'Tâche Asana non créée (projet client, assigné Asana ou API).');
            }
        }

        return $this->redirectToRoute('app_shooting_request_show', ['id' => $shootingRequest->getId()]);
    }

    private function renderNewForm(?int $defaultClientId): Response
    {
        $clients = $this->clientRepository->findAllOrderedByClientName();
        $employees = $this->userRepository->findEmployeesOrdered();
        $selectedClient = $defaultClientId !== null ? $this->clientRepository->find($defaultClientId) : null;

        $plannedVideos = $selectedClient !== null ? $this->findPlannedVideosForClient($selectedClient) : [];

        return $this->render('tournage/new.html.twig', [
            'clients' => $clients,
            'employees' => $employees,
            'defaultClientId' => $defaultClientId,
            'plannedVideos' => $plannedVideos,
        ]);
    }

    private function findVideoFormat(): ?Format
    {
        foreach ($this->formatRepository->findAllOrdered() as $format) {
            $name = mb_strtolower(trim((string) $format->getName()));
            if ($name === 'vidéo' || $name === 'video') {
                return $format;
            }
        }

        return null;
    }

    /**
     * @return Content[]
     */
    private function findPlannedVideosForClient(Client $client): array
    {
        $videoFormat = $this->findVideoFormat();
        $plannedStatus = $this->statusRepository->findOneByName('Tournage à prévoir');
        if ($videoFormat === null || $plannedStatus === null) {
            return [];
        }

        return $this->contentRepository->createQueryBuilder('c')
            ->leftJoin('c.videoCommunityManager', 'cm')->addSelect('cm')
            ->andWhere('c.client = :client')
            ->andWhere('c.format = :format')
            ->andWhere('c.status = :status')
            ->setParameter('client', $client)
            ->setParameter('format', $videoFormat)
            ->setParameter('status', $plannedStatus)
            ->orderBy('c.scheduledDate', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vidéos planifiées + déjà liées à la demande (pour édition).
     *
     * @return Content[]
     */
    private function findSelectableVideosForRequest(ShootingRequest $shootingRequest): array
    {
        $client = $shootingRequest->getClient();
        if ($client === null) {
            return [];
        }

        /** @var array<int, Content> $byId */
        $byId = [];
        foreach ($shootingRequest->getVideos() as $video) {
            if ($video->getId() !== null) {
                $byId[$video->getId()] = $video;
            }
        }
        foreach ($this->findPlannedVideosForClient($client) as $video) {
            if ($video->getId() !== null) {
                $byId[$video->getId()] = $video;
            }
        }

        $videos = array_values($byId);
        usort($videos, static function (Content $a, Content $b): int {
            $da = $a->getScheduledDate()?->format('Y-m-d') ?? '9999-99-99';
            $db = $b->getScheduledDate()?->format('Y-m-d') ?? '9999-99-99';
            $cmp = $da <=> $db;
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($a->getId() ?? 0) <=> ($b->getId() ?? 0);
        });

        return $videos;
    }
}
