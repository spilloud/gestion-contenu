<?php

namespace App\Service;

use App\Entity\Content;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Crée la tâche Asana « montage vidéo » lorsque la vidéo passe en « Montage à faire »
 * (dérush, bouton workflow, changement de statut manuel).
 */
final class VideoMontageAsanaTrigger
{
    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly VideoAssigneeResolver $assigneeResolver,
        private readonly ContentFormatHelper $formatHelper,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Applique le monteur/CM client si manquant, puis crée la tâche Asana montage si besoin.
     *
     * @return bool true si une nouvelle tâche Asana a été créée
     */
    public function ensureWhenMontageQueued(Content $content, bool $flush = true): bool
    {
        if (!$this->formatHelper->isVideoContent($content)) {
            return false;
        }
        if ($content->getStatus()?->getName() !== 'Montage à faire') {
            return false;
        }

        $this->assigneeResolver->applyClientTeamDefaultsForForm($content);

        if (!$this->asanaService->isEnabled() || $content->getAsanaTaskGid()) {
            if ($flush) {
                $this->entityManager->flush();
            }

            return false;
        }

        $fallback = getenv('ASANA_FALLBACK_ASSIGNEE_GID');
        $fallback = $fallback === false ? null : (string) $fallback;

        $videoUrl = $this->urlGenerator->generate(
            'app_video_show',
            ['id' => $content->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $gid = $this->asanaService->createTaskForVideo($content, $videoUrl, $fallback);
        if ($gid === null) {
            if ($flush) {
                $this->entityManager->flush();
            }

            return false;
        }

        $content->setAsanaTaskGid($gid);
        if ($flush) {
            $this->entityManager->flush();
        }

        return true;
    }
}
