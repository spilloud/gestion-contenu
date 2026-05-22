<?php

namespace App\Controller;

use App\Entity\Content;
use App\Entity\ContentComment;
use App\Form\ContentType;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\StatusRepository;
use App\Repository\UserRepository;
use App\Repository\ContentActionLogRepository;
use App\Service\AsanaService;
use App\Service\ContentFormatHelper;
use App\Service\ContentWorkflowService;
use App\Service\SubtitlesReviewAsanaTrigger;
use App\Workflow\ContentWorkflowRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/contenu')]
class ContentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentRepository $contentRepository,
        private readonly ClientRepository $clientRepository,
        private readonly StatusRepository $statusRepository,
        private readonly UserRepository $userRepository,
        private readonly AsanaService $asanaService,
        private readonly SubtitlesReviewAsanaTrigger $subtitlesReviewAsanaTrigger,
        private readonly ContentWorkflowService $contentWorkflowService,
        private readonly ContentWorkflowRegistry $contentWorkflowRegistry,
        private readonly ContentActionLogRepository $contentActionLogRepository,
        private readonly ContentFormatHelper $contentFormatHelper,
    ) {
    }

    #[Route('/nouveau', name: 'app_content_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $content = new Content();
        $clientId = $request->query->getInt('client');
        $defaultReturnTo = $this->resolveReturnTo($request);

        if ($clientId) {
            $client = $this->clientRepository->find($clientId);
            if ($client) {
                $content->setClient($client);
            }
        }
        $form = $this->createForm(ContentType::class, $content);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $isVideo = $this->isVideoContent($content);

            // `scheduledDate` est obligatoire en base, mais on autorise un champ vide ici :
            // - vidéos : par défaut +14 jours (comme dérush)
            // - autres formats : on exige une date
            if ($content->getScheduledDate() === null) {
                if ($isVideo) {
                    $content->setScheduledDate((new \DateTimeImmutable('today'))->modify('+14 days'));
                } else {
                    $this->addFlash('error', 'La date est obligatoire.');

                    return $this->render('content/new.html.twig', [
                        'content' => $content,
                        'form' => $form,
                        'returnTo' => $defaultReturnTo,
                    ]);
                }
            }

            if ($isVideo) {
                // On démarre le même process que le dérush (sans liens) :
                // - statut initial vidéo
                // - monteur auto depuis le client (si défini)
                // - sous-titres par défaut à non
                $content->setStatus($this->findInitialVideoStatus());

                $client = $content->getClient();
                if ($client) {
                    if ($content->getVideoEditor() === null && $client->getEditor() !== null) {
                        $content->setVideoEditor($client->getEditor());
                    }
                    if ($content->getVideoCommunityManager() === null && $client->getCommunityManager() !== null) {
                        $content->setVideoCommunityManager($client->getCommunityManager());
                    }
                }
                if ($content->getVideoHasSubtitles() === null) {
                    $content->setVideoHasSubtitles(false);
                }
            }

            if (!$isVideo && $content->getStatus() === null) {
                $content->setStatus($this->findInitialStandardStatus());
            }

            $this->entityManager->persist($content);
            $this->entityManager->flush();
            $this->contentWorkflowService->logCreation($content);
            $this->entityManager->flush();

            $this->addFlash('success', 'Contenu créé.');

            $returnTo = $this->normalizeReturnTo($request->request->getString('_return_to'), $request) ?? $defaultReturnTo;

            if ($isVideo) {
                return $this->redirectToRoute('app_video_show', [
                    'id' => $content->getId(),
                    'return_to' => $returnTo,
                ]);
            }

            return $this->redirect($returnTo);
        }

        return $this->render('content/new.html.twig', [
            'content' => $content,
            'form' => $form,
            'returnTo' => $defaultReturnTo,
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_content_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Content $content, Request $request): Response
    {
        if ($this->isVideoContent($content)) {
            $returnTo = $this->normalizeReturnTo($request->query->getString('return_to'), $request)
                ?? $this->normalizeReturnTo($request->headers->get('referer'), $request)
                ?? $this->generateUrl('app_calendar');

            return $this->redirectToRoute('app_video_show', [
                'id' => $content->getId(),
                'return_to' => $returnTo,
            ]);
        }

        $defaultReturnTo = $this->resolveReturnTo($request);

        $form = $this->createForm(ContentType::class, $content);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $content->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', 'Contenu modifié.');
            $returnTo = $this->normalizeReturnTo($request->request->getString('_return_to'), $request) ?? $defaultReturnTo;

            return $this->redirect($returnTo);
        }

        return $this->render('content/edit.html.twig', array_merge([
            'content' => $content,
            'form' => $form,
            'returnTo' => $defaultReturnTo,
        ], $this->buildWorkflowViewData($content)));
    }

    #[Route('/{id}/supprimer', name: 'app_content_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Content $content, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete'.$content->getId(), $request->request->getString('_token'))) {
            $this->entityManager->remove($content);
            $this->entityManager->flush();
            $this->addFlash('success', 'Contenu supprimé.');
        }

        $returnTo = $this->normalizeReturnTo($request->request->getString('_return_to'), $request);

        return $returnTo !== null ? $this->redirect($returnTo) : $this->redirectToRoute('app_calendar');
    }

    #[Route('/{id}/deplacer', name: 'app_content_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(Content $content, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('move'.$content->getId(), $request->request->getString('_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Jeton CSRF invalide. Rechargez la page.'], 403);
        }

        $dateStr = $request->request->getString('date');
        if ($dateStr === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Date manquante.'], 400);
        }

        try {
            $content->setScheduledDate(new \DateTime($dateStr));
            $content->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Impossible d\'enregistrer la date.'], 400);
        }

        return new JsonResponse([
            'ok' => true,
            'date' => $content->getScheduledDate()?->format('Y-m-d'),
        ]);
    }

    #[Route('/{id}/statut', name: 'app_content_change_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeStatus(Content $content, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('status'.$content->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_calendar');
        }

        $statusId = $request->request->getInt('statusId');
        if ($statusId > 0) {
            $status = $this->statusRepository->find($statusId);
            if ($status) {
                $this->contentWorkflowService->applyManualStatusChange($content, $status, 'calendrier');
            }
        }

        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_calendar');
    }

    #[Route('/{id}/workflow/{action}', name: 'app_content_workflow_transition', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function workflowTransition(Content $content, string $action, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('workflow'.$content->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectWorkflowBack($content, $request);
        }

        $result = $this->contentWorkflowService->applyTransition($content, $action);
        if ($result['ok']) {
            $this->addFlash('success', 'Étape enregistrée.');
        } else {
            $this->addFlash('error', $result['message'] ?? 'Action impossible.');
        }

        return $this->redirectWorkflowBack($content, $request);
    }

    #[Route('/{id}/workflow/reculer', name: 'app_content_workflow_step_back', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function workflowStepBack(Content $content, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('workflow'.$content->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectWorkflowBack($content, $request);
        }

        $result = $this->contentWorkflowService->stepBack($content);
        if ($result['ok']) {
            $this->addFlash('success', 'Retour à l\'étape précédente.');
        } else {
            $this->addFlash('error', $result['message'] ?? 'Recul impossible.');
        }

        return $this->redirectWorkflowBack($content, $request);
    }

    #[Route('/{id}/commenter', name: 'app_content_comment', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function comment(Content $content, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('comment'.$content->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            if ($this->isVideoContent($content)) {
                return $this->redirectToRoute('app_video_show', ['id' => $content->getId()]);
            }
            return $this->redirectToRoute('app_content_edit', ['id' => $content->getId()]);
        }

        $message = trim($request->request->getString('message'));
        if ($message !== '') {
            $comment = new ContentComment();
            $comment->setContent($content);
            $comment->setMessage($message);
            $user = $this->getUser();
            if ($user instanceof \App\Entity\User) {
                $comment->setAuthor($user);
            }
            $this->entityManager->persist($comment);
            $content->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            // Synchronisation Asana (best-effort) : ajout du commentaire sur la tâche liée
            $taskGid = $content->getAsanaTaskGid();
            if ($taskGid && $this->isVideoContent($content) && $this->asanaService->isEnabled()) {
                $authorName = ($user instanceof \App\Entity\User) ? ($user->getName() ?? $user->getUserIdentifier()) : '—';
                $mention = $authorName ? '@'.$authorName : '';
                $videoUrl = $this->generateUrl('app_video_show', ['id' => $content->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
                $text = trim(implode("\n", array_filter([
                    $mention !== '' ? $mention.' (via Lucy)' : 'Commentaire (via Lucy)',
                    'Vidéo : '.$videoUrl,
                    '',
                    $message,
                ])));
                $this->asanaService->addCommentToTask($taskGid, $text);
            }
        }

        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        if ($this->isVideoContent($content)) {
            return $this->redirectToRoute('app_video_show', ['id' => $content->getId()]);
        }

        return $this->redirectToRoute('app_content_edit', ['id' => $content->getId()]);
    }

    private function redirectBackFromMove(Request $request, Content $content): Response
    {
        $referer = $this->normalizeReturnTo($request->headers->get('referer'), $request);
        if ($referer !== null) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_calendar', [
            'month' => $content->getScheduledDate()?->format('n'),
            'year' => $content->getScheduledDate()?->format('Y'),
        ]);
    }

    private function resolveReturnTo(Request $request): string
    {
        $returnToFromQuery = $this->normalizeReturnTo($request->query->getString('return_to'), $request);
        if ($returnToFromQuery !== null) {
            return $returnToFromQuery;
        }

        $returnToFromReferer = $this->normalizeReturnTo($request->headers->get('referer'), $request);
        if ($returnToFromReferer !== null) {
            return $returnToFromReferer;
        }

        return $this->generateUrl('app_calendar');
    }

    private function normalizeReturnTo(?string $value, Request $request): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, '/')) {
            return str_starts_with($value, '//') ? null : $value;
        }

        $parts = parse_url($value);
        if ($parts === false || !isset($parts['host']) || !isset($parts['path'])) {
            return null;
        }

        if (strcasecmp((string) $parts['host'], $request->getHost()) !== 0) {
            return null;
        }

        $path = (string) $parts['path'];
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path !== '' ? $path.$query : null;
    }

    private function isVideoContent(Content $content): bool
    {
        return $this->contentFormatHelper->isVideoContent($content);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWorkflowViewData(Content $content): array
    {
        $journey = [];
        foreach ($this->contentActionLogRepository->findVisibleJourneyForContent($content) as $log) {
            $journey[] = [
                'label' => $log->getLabel(),
                'detail' => $log->getDetail(),
                'createdAt' => $log->getCreatedAt(),
                'userName' => $log->getUser()?->getName(),
            ];
        }

        return [
            'workflow_actions' => $this->contentWorkflowRegistry->availableActions($content),
            'workflow_can_step_back' => $this->contentWorkflowRegistry->previousStatusName($content) !== null,
            'workflow_journey' => $journey,
            'workflow_phases' => $this->contentWorkflowRegistry->phasesFor($content),
            'workflow_phase_index' => $this->contentWorkflowRegistry->phaseIndexFor($content),
        ];
    }

    private function redirectWorkflowBack(Content $content, Request $request): Response
    {
        if ($this->isVideoContent($content)) {
            return $this->redirectToRoute('app_video_show', ['id' => $content->getId()]);
        }

        $returnTo = $this->normalizeReturnTo($request->request->getString('_return_to'), $request);
        if ($returnTo !== null) {
            return $this->redirect($returnTo);
        }

        return $this->redirectToRoute('app_content_edit', ['id' => $content->getId()]);
    }

    private function findInitialStandardStatus(): \App\Entity\Status
    {
        $status = $this->statusRepository->findOneByName('Brouillon (idée)');
        if ($status !== null) {
            return $status;
        }

        $status = new \App\Entity\Status();
        $status->setName('Brouillon (idée)');
        $status->setColor(\App\Entity\Status::COLOR_GRAY);
        $status->setSortOrder(10);
        $status->setWorkflow(\App\Entity\Status::WORKFLOW_STANDARD);
        $this->entityManager->persist($status);
        $this->entityManager->flush();

        return $status;
    }

    private function findInitialVideoStatus(): \App\Entity\Status
    {
        foreach ($this->statusRepository->findAllOrdered() as $status) {
            if ($status->getName() === 'Brouillon (Dérush)') {
                return $status;
            }
        }

        // fallback: create if missing (safe for fresh DBs)
        $status = new \App\Entity\Status();
        $status->setName('Brouillon (Dérush)');
        $status->setColor(\App\Entity\Status::COLOR_GRAY);
        $status->setSortOrder(100);
        $status->setWorkflow(\App\Entity\Status::WORKFLOW_VIDEO);
        $this->entityManager->persist($status);
        $this->entityManager->flush();

        return $status;
    }
}
