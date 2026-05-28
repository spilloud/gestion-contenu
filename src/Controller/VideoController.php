<?php

namespace App\Controller;

use App\Entity\Content;
use App\Entity\Format;
use App\Form\VideoContentType;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\FormatRepository;
use App\Repository\StatusRepository;
use App\Repository\ContentActionLogRepository;
use App\Repository\UserRepository;
use App\Service\ContentFormatHelper;
use App\Service\SubtitlesReviewAsanaTrigger;
use App\Service\VideoAssigneeResolver;
use App\Workflow\ContentWorkflowRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/videos')]
class VideoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentRepository $contentRepository,
        private readonly ClientRepository $clientRepository,
        private readonly StatusRepository $statusRepository,
        private readonly FormatRepository $formatRepository,
        private readonly UserRepository $userRepository,
        private readonly ContentFormatHelper $contentFormatHelper,
        private readonly SubtitlesReviewAsanaTrigger $subtitlesReviewAsanaTrigger,
        private readonly ContentWorkflowRegistry $contentWorkflowRegistry,
        private readonly ContentActionLogRepository $contentActionLogRepository,
        private readonly VideoAssigneeResolver $videoAssigneeResolver,
    ) {
    }

    #[Route('', name: 'app_videos_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $videoFormat = $this->findVideoFormat();

        $clientIds = $request->query->all('clients') ?: null;
        $statusIds = $request->query->all('statuses') ?: null;
        $editorIds = $request->query->all('editors') ?: null;
        [$sort, $dir] = $this->resolveListSort($request);

        $qb = $this->contentRepository->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('cl.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->leftJoin('c.videoEditor', 'e')->addSelect('e')
            ->andWhere('c.format = :format')
            ->setParameter('format', $videoFormat);

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
        if (!empty($editorIds)) {
            $qb->andWhere('c.videoEditor IN (:editorIds)')
                ->setParameter('editorIds', $editorIds);
        }

        return $this->render('videos/index.html.twig', [
            'videoFormat' => $videoFormat,
            'contents' => $qb->getQuery()->getResult(),
            'clients' => $this->clientRepository->findAllOrderedByClientName(),
            'statuses' => $this->statusRepository->findForWorkflow(\App\Entity\Status::WORKFLOW_VIDEO),
            'editors' => $this->userRepository->findBy([], ['name' => 'ASC']),
            'selectedClientIds' => $clientIds ?? [],
            'selectedStatusIds' => $statusIds ?? [],
            'selectedEditorIds' => $editorIds ?? [],
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
        // Par défaut : les plus récentes en haut.
        $dir = strtolower($request->query->getString('dir', 'desc')) === 'desc' ? 'DESC' : 'ASC';

        return [$sort, $dir];
    }

    #[Route('/fiche/{id}', name: 'app_video_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Content $content, Request $request): Response
    {
        // Accepte aussi les formats "vidéo-like" (ex: carrousel/carousel).
        if (!$this->contentFormatHelper->isVideoContent($content)) {
            throw $this->createNotFoundException();
        }

        $defaultReturnTo = $this->resolveReturnTo($request);

        if ($request->isMethod('GET')) {
            $editorBefore = $content->getVideoEditor();
            $cmBefore = $content->getVideoCommunityManager();
            $this->videoAssigneeResolver->applyClientTeamDefaultsForForm($content);
            if ($content->getVideoEditor() !== $editorBefore || $content->getVideoCommunityManager() !== $cmBefore) {
                $content->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
            }
        }

        $form = $this->createForm(VideoContentType::class, $content);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $content->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->subtitlesReviewAsanaTrigger->ensureWhenStatusIsSubtitlesReview($content);

            $this->addFlash('success', 'Vidéo enregistrée.');

            $returnTo = $this->normalizeReturnTo($request->request->getString('_return_to'), $request) ?? $defaultReturnTo;

            return $this->redirect($returnTo);
        }

        return $this->render('videos/show.html.twig', array_merge([
            'content' => $content,
            'form' => $form,
            'returnTo' => $defaultReturnTo,
            'cm_display_name' => $this->videoAssigneeResolver->displayNameForCm($content),
        ], $this->buildWorkflowViewData($content)));
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

    private function findVideoFormat(): Format
    {
        foreach ($this->formatRepository->findAllOrdered() as $format) {
            $name = mb_strtolower(trim((string) $format->getName()));
            if ($name === 'vidéo' || $name === 'video') {
                return $format;
            }
        }

        throw $this->createNotFoundException('Format vidéo introuvable (migration non appliquée).');
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

        return $this->generateUrl('app_videos_index');
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
}

