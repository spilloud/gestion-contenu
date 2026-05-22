<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\User;

/**
 * Réassigne les tâches Asana montage / sous-titres quand monteur ou CM changent sur la fiche.
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

    public function syncSubtitlesAfterCommunityManagerChange(
        Content $content,
        ?User $previous,
        ?User $next,
    ): void {
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
            $name = $next?->getName() ?? $this->assigneeResolver->displayNameForCm($content);
            $this->asanaService->addCommentToTask(
                $taskGid,
                "Community manager réassignée (via Gestion des contenus) : $name",
            );
        }
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
