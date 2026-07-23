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
        private readonly VideoMontageAsanaTrigger $montageAsanaTrigger,
    ) {
    }

    public function syncMontageDueOnIfChanged(
        Content $content,
        ?\DateTimeImmutable $previous,
        ?\DateTimeImmutable $next,
    ): void {
        if (!$this->formatHelper->isVideoContent($content)) {
            return;
        }
        if ($previous?->format('Y-m-d') === $next?->format('Y-m-d')) {
            return;
        }
        if ($next === null) {
            return;
        }

        $taskGid = $this->montageAsanaTrigger->resolveMontageTaskLink($content, true);
        if ($taskGid === null || !$this->asanaService->isEnabled()) {
            return;
        }

        if ($this->asanaService->updateTaskDueOn($taskGid, $next)) {
            $content->markAsanaMontageDueOnPushedFromLucy();
            $this->asanaService->addCommentToTask(
                $taskGid,
                'Échéance montage mise à jour (via Gestion des contenus) : '.$next->format('d/m/Y'),
            );
        }
    }

    public function syncMontageAssigneeIfChanged(Content $content, ?User $previous, ?User $next): void
    {
        if (!$this->formatHelper->isVideoContent($content)) {
            return;
        }
        if ($this->sameUser($previous, $next)) {
            return;
        }

        $taskGid = $this->montageAsanaTrigger->resolveMontageTaskLink($content, true);
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
