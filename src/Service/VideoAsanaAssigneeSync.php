<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\User;

/**
 * Réassigne les tâches Asana montage / création post / sous-titres quand monteur ou CM changent.
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
        if ($previous?->format('Y-m-d') === $next?->format('Y-m-d')) {
            return;
        }
        if ($next === null) {
            return;
        }

        $taskGid = $this->resolvePrimaryTaskGid($content);
        if ($taskGid === null || !$this->asanaService->isEnabled()) {
            return;
        }

        if ($this->asanaService->updateTaskDueOn($taskGid, $next)) {
            $content->markAsanaMontageDueOnPushedFromLucy();
            $this->asanaService->addCommentToTask(
                $taskGid,
                'Échéance mise à jour (via Gestion des contenus) : '.$next->format('d/m/Y'),
            );
        }
    }

    public function syncMontageAssigneeIfChanged(Content $content, ?User $previous, ?User $next): void
    {
        if ($this->sameUser($previous, $next)) {
            return;
        }
        if (!$this->asanaService->isEnabled()) {
            return;
        }

        $taskGid = $this->resolvePrimaryTaskGid($content);
        if ($taskGid === null
            && $this->formatHelper->isVideoContent($content)
            && $content->getStatus()?->getName() === 'Montage à faire') {
            $this->montageAsanaTrigger->ensureWhenMontageQueued($content, true);

            return;
        }
        if ($taskGid === null) {
            return;
        }

        $assigneeGid = $this->assigneeResolver->asanaGidForMontage($content);
        if ($assigneeGid === null) {
            return;
        }

        if ($this->asanaService->updateTaskAssignee($taskGid, $assigneeGid)) {
            $name = $next?->getName() ?? '—';
            $role = $this->formatHelper->isVideoContent($content) ? 'Monteur' : 'Médiamaticien';
            $this->asanaService->addCommentToTask(
                $taskGid,
                "$role réassigné (via Gestion des contenus) : $name",
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

    private function resolvePrimaryTaskGid(Content $content): ?string
    {
        $stored = trim((string) ($content->getAsanaTaskGid() ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        if ($this->formatHelper->isVideoContent($content)) {
            return $this->montageAsanaTrigger->resolveMontageTaskLink($content, true);
        }

        return null;
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
