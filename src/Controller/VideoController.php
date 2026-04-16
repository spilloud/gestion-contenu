<?php

namespace App\Controller;

use App\Entity\Content;
use App\Entity\Format;
use App\Form\VideoContentType;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\FormatRepository;
use App\Repository\StatusRepository;
use App\Repository\UserRepository;
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
    ) {
    }

    #[Route('', name: 'app_videos_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $videoFormat = $this->findVideoFormat();

        $clientIds = $request->query->all('clients') ?: null;
        $statusIds = $request->query->all('statuses') ?: null;
        $editorIds = $request->query->all('editors') ?: null;

        $qb = $this->contentRepository->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('cl.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->leftJoin('c.videoEditor', 'e')->addSelect('e')
            ->andWhere('c.format = :format')
            ->setParameter('format', $videoFormat)
            ->orderBy('c.scheduledDate', 'ASC')
            ->addOrderBy('cl.name', 'ASC');

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
            'statuses' => $this->statusRepository->findAllOrdered(),
            'editors' => $this->userRepository->findBy([], ['name' => 'ASC']),
            'selectedClientIds' => $clientIds ?? [],
            'selectedStatusIds' => $statusIds ?? [],
            'selectedEditorIds' => $editorIds ?? [],
        ]);
    }

    #[Route('/fiche/{id}', name: 'app_video_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Content $content, Request $request): Response
    {
        $videoFormat = $this->findVideoFormat();
        if ($content->getFormat()?->getId() !== $videoFormat->getId()) {
            throw $this->createNotFoundException();
        }

        $defaultReturnTo = $this->resolveReturnTo($request);

        $form = $this->createForm(VideoContentType::class, $content);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $content->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', 'Vidéo enregistrée.');

            $returnTo = $this->normalizeReturnTo($request->request->getString('_return_to'), $request) ?? $defaultReturnTo;

            return $this->redirect($returnTo);
        }

        return $this->render('videos/show.html.twig', [
            'content' => $content,
            'form' => $form,
            'returnTo' => $defaultReturnTo,
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

