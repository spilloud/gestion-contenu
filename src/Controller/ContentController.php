<?php

namespace App\Controller;

use App\Entity\Content;
use App\Entity\ContentComment;
use App\Form\ContentType;
use App\Entity\Format;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\FormatRepository;
use App\Repository\StatusRepository;
use App\Repository\UserRepository;
use App\Service\AsanaService;
use App\Service\ContentFormatHelper;
use App\Service\ContentWorkflowService;
use App\Service\ContentWorkflowViewBuilder;
use App\Service\SubtitlesReviewAsanaTrigger;
use App\Service\VideoAssigneeResolver;
use App\Service\VideoMontageAsanaTrigger;
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
        private readonly ContentWorkflowViewBuilder $workflowViewBuilder,
        private readonly ContentFormatHelper $contentFormatHelper,
        private readonly FormatRepository $formatRepository,
        private readonly VideoAssigneeResolver $videoAssigneeResolver,
        private readonly VideoMontageAsanaTrigger $montageAsanaTrigger,
    ) {
    }

    #[Route('', name: 'app_contents_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $videoFormat = $this->findVideoFormat();

        $clientIds = $request->query->all('clients') ?: null;
        $statusIds = $request->query->all('statuses') ?: null;
        $formatIds = $request->query->all('formats') ?: null;
        [$sort, $dir] = $this->resolveListSort($request);

        $qb = $this->contentRepository->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('cl.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->leftJoin('c.format', 'f')->addSelect('f')
            ->andWhere('c.format != :videoFormat')
            ->setParameter('videoFormat', $videoFormat);

        if ($sort === 'client') {
            $qb->orderBy('cl.name', $dir)->addOrderBy('c.scheduledDate', 'ASC');
        } else {
            $qb->orderBy('c.scheduledDate', $dir)->addOrderBy('cl.name', 'ASC');
        }

        if (!empty($clientIds)) {
            $qb->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $clientIds);
        }
        if (!empty($statusIds)) {
            $qb->andWhere('c.status IN (:statusIds)')
                ->setParameter('statusIds', $statusIds);
        }
        if (!empty($formatIds)) {
            $qb->andWhere('c.format IN (:formatIds)')
                ->setParameter('formatIds', $formatIds);
        }

        $nonVideoFormats = array_values(array_filter(
            $this->formatRepository->findAllOrdered(),
            fn (Format $format) => !$this->contentFormatHelper->isVideoFormat($format),
        ));

        return $this->render('content/index.html.twig', [
            'contents' => $qb->getQuery()->getResult(),
            'clients' => $this->clientRepository->findAllOrderedByClientName(),
            'statuses' => $this->statusRepository->findForWorkflow(\App\Entity\Status::WORKFLOW_STANDARD),
            'formats' => $nonVideoFormats,
            'selectedClientIds' => $clientIds ?? [],
            'selectedStatusIds' => $statusIds ?? [],
            'selectedFormatIds' => $formatIds ?? [],
            'sort' => $sort,
            'dir' => strtolower($dir),
        ]);
    }

    /**
     * @return array{0: string, 1: string} [sort, dir SQL]
     */
    private function resolveListSort(Request $request): array
    {
        $sort = $request->query->getString('sort', 'date');
        if (!in_array($sort, ['date', 'client'], true)) {
            $sort = 'date';
        }
        $dir = strtolower($request->query->getString('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';

        return [$sort, $dir];
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
                // Création "planning" (CM) : on planifie une vidéo avec date,
                // sans déclencher Asana (créé uniquement depuis la page Dérush).
                // - monteur auto depuis le client (si défini)
                // - sous-titres par défaut à non
                $content->setStatus($this->findPlannedVideoStatus());

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

            if (!$isVideo) {
                $this->videoAssigneeResolver->applyClientTeamDefaultsForForm($content);
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

            return $this->redirectToRoute('app_content_edit', [
                'id' => $content->getId(),
                'return_to' => $returnTo,
            ]);
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

        if ($request->isMethod('GET')) {
            $this->videoAssigneeResolver->applyClientTeamDefaultsForForm($content);
        }

        $form = $this->createForm(ContentType::class, $content);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $content->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', 'Contenu modifié.');
            $returnTo = $this->normalizeReturnTo($request->request->getString('_return_to'), $request) ?? $defaultReturnTo;

            $params = ['id' => $content->getId()];
            if ($returnTo !== null && $returnTo !== '') {
                $params['return_to'] = $returnTo;
            }

            return $this->redirectToRoute('app_content_edit', $params);
        }

        return $this->render('content/edit.html.twig', array_merge([
            'content' => $content,
            'form' => $form,
            'returnTo' => $defaultReturnTo,
            'cm_display_name' => $this->videoAssigneeResolver->displayNameForCm($content),
        ], $this->workflowViewBuilder->build($content)));
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
            $taskGid = $this->isVideoContent($content)
                ? $this->montageAsanaTrigger->resolveMontageTaskLink($content, true)
                : $content->getAsanaTaskGid();
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

    private function redirectWorkflowBack(Content $content, Request $request): Response
    {
        $returnTo = $this->normalizeReturnTo($request->request->getString('_return_to'), $request);
        $params = ['id' => $content->getId()];
        if ($returnTo !== null) {
            $params['return_to'] = $returnTo;
        }

        if ($this->isVideoContent($content)) {
            return $this->redirectToRoute('app_video_show', $params);
        }

        return $this->redirectToRoute('app_content_edit', $params);
    }

    private function findVideoFormat(): Format
    {
        foreach ($this->formatRepository->findAllOrdered() as $format) {
            if ($this->contentFormatHelper->isVideoFormat($format)) {
                return $format;
            }
        }

        throw $this->createNotFoundException('Format vidéo introuvable.');
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

    private function findPlannedVideoStatus(): \App\Entity\Status
    {
        $status = $this->statusRepository->findOneByName('Tournage à prévoir');
        if ($status !== null) {
            return $status;
        }

        $status = new \App\Entity\Status();
        $status->setName('Tournage à prévoir');
        $status->setColor(\App\Entity\Status::COLOR_RED);
        $status->setSortOrder(5);
        $status->setWorkflow(\App\Entity\Status::WORKFLOW_VIDEO);
        $this->entityManager->persist($status);
        $this->entityManager->flush();

        return $status;
    }
}
