<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\User;

/**
 * Réassigne les tâches Asana montage / sous-titres quand les responsables changent sur la fiche.
 */
final class VideoAsanaAssigneeSync
{
    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly VideoAssigneeResolver $assigneeResolver,
        private readonly ContentFormatHelper $formatHelper,
    ) {
    }

    public function syncMontageAssigneeIfChanged(Content $content, ?User $previous, ?User $next): void
    {
        if (!$this->formatHelper->isVideoContent($content)) {
            return;
        }
        if ($this->sameUser($previous, $next)) {
            return;
        }

        $taskGid = $content->getAsanaTaskGid();
        if ($taskGid === null || !$this->asanaService->isEnabled()) {
            return;
        }

        $assigneeGid = $this->assigneeResolver->asanaGidForMontage($content);
        if ($assigneeGid === null) {
            return;
        }

        if ($this->asanaService->updateTaskAssignee($taskGid, $assigneeGid)) {
            $name = $next?->getName() ?? '—';
            $this->asanaService->addCommentToTask(
                $taskGid,
                "Monteur réassigné (via Gestion des contenus) : $name",
            );
        }
    }

    public function syncSubtitlesAssigneeIfChanged(Content $content, ?User $previous, ?User $next): void
    {
        if (!$this->formatHelper->isVideoContent($content)) {
            return;
        }
        if ($this->sameUser($previous, $next)) {
            return;
        }

        $taskGid = $content->getAsanaSubtitlesTaskGid();
        if ($taskGid === null || !$this->asanaService->isEnabled()) {
            return;
        }

        $assigneeGid = $this->assigneeResolver->asanaGidForSubtitlesReview($content);
        if ($assigneeGid === null) {
            return;
        }

        if ($this->asanaService->updateTaskAssignee($taskGid, $assigneeGid)) {
            $name = $next?->getName() ?? $this->assigneeResolver->displayNameForSubtitlesReviewer($content);
            $this->asanaService->addCommentToTask(
                $taskGid,
                "Relecteur sous-titres réassigné (via Gestion des contenus) : $name",
            );
        }
    }

    /**
     * La CM déléguée peut impacter la tâche sous-titres si aucun relecteur dédié.
     */
    public function syncSubtitlesAfterCmChange(Content $content, ?User $previous, ?User $next): void
    {
        if ($content->getVideoSubtitlesReviewer() !== null) {
            return;
        }

        $this->syncSubtitlesAssigneeIfChanged($content, $previous, $next);
    }

    private function sameUser(?User $a, ?User $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }

        return $a->getId() === $b->getId();
    }
}
