<?php

namespace App\Controller;

use App\Entity\Content;
use App\Entity\ContentComment;
use App\Form\ContentType;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\StatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/contenu')]
class ContentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentRepository $contentRepository,
        private readonly ClientRepository $clientRepository,
        private readonly StatusRepository $statusRepository,
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
                if ($client && $content->getVideoEditor() === null && $client->getEditor() !== null) {
                    $content->setVideoEditor($client->getEditor());
                }
                if ($content->getVideoHasSubtitles() === null) {
                    $content->setVideoHasSubtitles(false);
                }
            }

            $this->entityManager->persist($content);
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

        return $this->render('content/edit.html.twig', [
            'content' => $content,
            'form' => $form,
            'returnTo' => $defaultReturnTo,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'app_content_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Content $content, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete'.$content->getId(), $request->request->getString('_token'))) {
            $this->entityManager->remove($content);
            $this->entityManager->flush();
            $this->addFlash('success', 'Contenu supprimé.');
        }

        return $this->redirectToRoute('app_calendar');
    }

    #[Route('/{id}/deplacer', name: 'app_content_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(Content $content, Request $request): Response
    {
        $dateStr = $request->request->getString('date');
        if ($dateStr && $this->isCsrfTokenValid('move'.$content->getId(), $request->request->getString('_token'))) {
            try {
                $content->setScheduledDate(new \DateTimeImmutable($dateStr));
                $this->entityManager->flush();
                $this->addFlash('success', 'Contenu déplacé.');
            } catch (\Exception) {
                $this->addFlash('error', 'Date invalide.');
            }
        }

        return $this->redirectToRoute('app_calendar', [
            'month' => $content->getScheduledDate()?->format('n'),
            'year' => $content->getScheduledDate()?->format('Y'),
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
                $content->setStatus($status);
                $content->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
            }
        }

        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_calendar');
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
        $name = mb_strtolower(trim((string) ($content->getFormat()?->getName() ?? '')));

        return $name === 'vidéo' || $name === 'video';
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
        $status->setSortOrder(999);
        $this->entityManager->persist($status);
        $this->entityManager->flush();

        return $status;
    }
}
