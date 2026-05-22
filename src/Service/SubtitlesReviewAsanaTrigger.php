<?php

namespace App\Service;

use App\Entity\Content;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Crée la tâche Asana « relecture sous-titres » lorsque le statut vidéo passe sur « Sous-titres à valider ».
 * (Même logique que le flux calendrier — évite d’oublier la fiche vidéo.)
 */
final class SubtitlesReviewAsanaTrigger
{
    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly VideoAssigneeResolver $assigneeResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function ensureWhenStatusIsSubtitlesReview(Content $content): void
    {
        $formatName = mb_strtolower(trim((string) ($content->getFormat()?->getName() ?? '')));
        if ($formatName !== 'vidéo' && $formatName !== 'video') {
            return;
        }

        if (!$this->asanaService->isEnabled()) {
            return;
        }
        if ($content->getAsanaSubtitlesTaskGid()) {
            return;
        }
        if ($content->getStatus()?->getName() !== 'Sous-titres à valider') {
            return;
        }

        $assigneeGid = $this->assigneeResolver->asanaGidForSubtitlesReview($content);
        $fallback = getenv('ASANA_FALLBACK_ASSIGNEE_GID');
        $fallback = $fallback === false ? null : (string) $fallback;

        $videoUrl = $this->urlGenerator->generate('app_video_show', ['id' => $content->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        $gid = $this->asanaService->createSubtitlesReviewTaskForVideo($content, $videoUrl, $assigneeGid, $fallback);
        if ($gid) {
            $content->setAsanaSubtitlesTaskGid($gid);
            $this->entityManager->flush();
        }
    }
}
