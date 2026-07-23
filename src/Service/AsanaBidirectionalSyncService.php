<?php

namespace App\Service;

use App\Entity\AsanaLinkedTask;
use App\Entity\Client;
use App\Entity\Content;
use App\Entity\ContentActionLog;
use App\Entity\ShootingRequest;
use App\Repository\AsanaLinkedTaskRepository;
use App\Repository\ContentRepository;
use App\Repository\ShootingRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronisation bidirectionnelle Asana ↔ Lucy (assigné, échéance, complétion, journal).
 */
final class AsanaBidirectionalSyncService
{
    /** Statuts vidéo où la complétion Asana montage doit avancer le workflow. */
    private const MONTAGE_ACTIVE_STATUSES = [
        'Montage à faire',
        'Montage en cours',
        'Retouches (Monteur)',
    ];

    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly ContentFormatHelper $formatHelper,
        private readonly WorkflowJournalFormatter $journalFormatter,
        private readonly UserRepository $userRepository,
        private readonly ContentRepository $contentRepository,
        private readonly AsanaLinkedTaskRepository $asanaLinkedTaskRepository,
        private readonly ShootingRequestRepository $shootingRequestRepository,
        private readonly ContentWorkflowService $contentWorkflowService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function syncContent(Content $content, bool $flush = true): bool
    {
        if (!$this->formatHelper->isVideoContent($content) || !$this->asanaService->isEnabled()) {
            return false;
        }

        $changed = $this->syncMontageTask($content)
            || $this->syncSubtitlesTask($content);

        if ($changed && $flush) {
            $this->entityManager->flush();
        }

        return $changed;
    }

    /**
     * @return int Nombre de vidéos mises à jour
     */
    public function syncContentsForClient(Client $client, bool $flush = true): int
    {
        if (!$this->asanaService->isEnabled()) {
            return 0;
        }

        $updated = 0;
        foreach ($this->contentRepository->findVideosWithAsanaLinksForClient($client) as $content) {
            if ($this->syncContent($content, false)) {
                ++$updated;
            }
        }

        if ($updated > 0 && $flush) {
            $this->entityManager->flush();
        }

        return $updated;
    }

    /**
     * @return array{updated: int, errors: int, skipped: int}
     */
    public function syncAll(): array
    {
        if (!$this->asanaService->isEnabled()) {
            return ['updated' => 0, 'errors' => 0, 'skipped' => 0];
        }

        $updated = 0;
        $errors = 0;
        $skipped = 0;

        foreach ($this->contentRepository->findVideosForAsanaSync() as $content) {
            try {
                if ($this->syncContent($content, false)) {
                    ++$updated;
                } else {
                    ++$skipped;
                }
            } catch (\Throwable) {
                ++$errors;
            }
        }

        foreach ($this->asanaLinkedTaskRepository->findOpenTasks() as $linkedTask) {
            try {
                if ($this->syncLinkedTask($linkedTask, false)) {
                    ++$updated;
                } else {
                    ++$skipped;
                }
            } catch (\Throwable) {
                ++$errors;
            }
        }

        foreach ($this->shootingRequestRepository->findWithOpenAsanaTask() as $request) {
            try {
                if ($this->syncShootingRequest($request, false)) {
                    ++$updated;
                } else {
                    ++$skipped;
                }
            } catch (\Throwable) {
                ++$errors;
            }
        }

        if ($updated > 0) {
            $this->entityManager->flush();
        }

        return ['updated' => $updated, 'errors' => $errors, 'skipped' => $skipped];
    }

    /**
     * @param list<Content> $videos
     */
    public function registerDerushFollowUpTask(Client $client, array $videos, string $taskGid): AsanaLinkedTask
    {
        $contentIds = [];
        foreach ($videos as $video) {
            if ($video->getId() !== null) {
                $contentIds[] = $video->getId();
            }
        }

        $existing = $this->asanaLinkedTaskRepository->findOneBy(['taskGid' => trim($taskGid)]);
        if ($existing instanceof AsanaLinkedTask) {
            return $existing;
        }

        $linked = new AsanaLinkedTask();
        $linked->setTaskGid($taskGid);
        $linked->setKind(AsanaLinkedTask::KIND_DERUSH_FOLLOWUP);
        $linked->setClient($client);
        $linked->setContentIds($contentIds);
        $this->entityManager->persist($linked);

        return $linked;
    }

    public function syncLinkedTask(AsanaLinkedTask $linkedTask, bool $flush = true): bool
    {
        if (!$linkedTask->isOpen() || !$this->asanaService->isEnabled()) {
            return false;
        }

        $gid = trim((string) ($linkedTask->getTaskGid() ?? ''));
        if ($gid === '') {
            return false;
        }

        $task = $this->asanaService->fetchTask($gid);
        if ($task === null) {
            return false;
        }

        $completed = (bool) ($task['completed'] ?? false);
        if (!$completed) {
            return false;
        }

        $linkedTask->setCompletedAtLucy(new \DateTimeImmutable());
        $label = 'Suivi dérush CM terminé (Asana)';

        foreach ($linkedTask->getContentIds() as $contentId) {
            $content = $this->contentRepository->find($contentId);
            if (!$content instanceof Content) {
                continue;
            }
            $this->persistAsanaLog($content, $label, 'Tâche Asana cochée par la CM.');
        }

        if ($flush) {
            $this->entityManager->flush();
        }

        return true;
    }

    public function syncShootingRequest(ShootingRequest $request, bool $flush = true): bool
    {
        $gid = trim((string) ($request->getAsanaTaskGid() ?? ''));
        if ($gid === '' || !$this->asanaService->isEnabled()) {
            return false;
        }

        $task = $this->asanaService->fetchTask($gid);
        if ($task === null) {
            return false;
        }

        $changed = false;
        $changed = $this->syncShootingAssignee($request, $task) || $changed;
        $changed = $this->syncShootingDueOn($request, $task) || $changed;
        $changed = $this->syncShootingCompleted($request, $task) || $changed;

        if ($changed && $flush) {
            $this->entityManager->flush();
        }

        return $changed;
    }

    private function syncMontageTask(Content $content): bool
    {
        $gid = $content->getAsanaTaskGid();
        if ($gid === null) {
            return false;
        }

        $task = $this->asanaService->fetchTask($gid);
        if ($task === null) {
            return false;
        }

        $changed = false;
        $changed = $this->syncMontageCompleted($content, $task) || $changed;
        $changed = $this->syncAssigneeFromTask($content, $task, 'montage') || $changed;
        $changed = $this->syncMontageDueOn($content, $task) || $changed;

        return $changed;
    }

    private function syncSubtitlesTask(Content $content): bool
    {
        $gid = $content->getAsanaSubtitlesTaskGid();
        if ($gid === null) {
            return false;
        }

        $task = $this->asanaService->fetchTask($gid);
        if ($task === null) {
            return false;
        }

        $changed = false;
        $changed = $this->syncSubtitlesCompleted($content, $task) || $changed;
        $changed = $this->syncAssigneeFromTask($content, $task, 'subtitles') || $changed;

        return $changed;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function syncMontageCompleted(Content $content, array $task): bool
    {
        $completed = (bool) ($task['completed'] ?? false);
        $statusName = $content->getStatus()?->getName() ?? '';

        if ($completed) {
            if (!in_array($statusName, self::MONTAGE_ACTIVE_STATUSES, true)) {
                return false;
            }

            $result = $this->contentWorkflowService->applyTransition($content, 'montage_done', fromAsana: true);
            if ($result['ok']) {
                $this->persistAsanaLog(
                    $content,
                    'Montage terminé (Asana)',
                    'Tâche Asana cochée — statut avancé automatiquement vers « À valider (Prod) ».',
                );

                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function syncSubtitlesCompleted(Content $content, array $task): bool
    {
        $completed = (bool) ($task['completed'] ?? false);
        $statusName = $content->getStatus()?->getName() ?? '';

        if ($completed) {
            if ($statusName !== 'Sous-titres à valider') {
                return false;
            }

            $result = $this->contentWorkflowService->applyTransition($content, 'subtitles_validated', fromAsana: true);
            if ($result['ok']) {
                $this->persistAsanaLog(
                    $content,
                    'Sous-titres validés (Asana)',
                    'Tâche Asana cochée — statut avancé automatiquement vers « À valider (CM) ».',
                );

                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function syncAssigneeFromTask(Content $content, array $task, string $kind): bool
    {
        $assignee = $task['assignee'] ?? null;
        if (!is_array($assignee)) {
            return false;
        }

        $assigneeGid = trim((string) ($assignee['gid'] ?? ''));
        if ($assigneeGid === '') {
            return false;
        }

        $user = $this->userRepository->findOneByAsanaUserGid($assigneeGid);

        if ($kind === 'montage') {
            $current = $content->getVideoEditor();
            $currentGid = trim((string) ($current?->getAsanaUserGid() ?? ''));
            if ($currentGid === $assigneeGid) {
                return false;
            }

            $previous = $current;
            if ($user === null) {
                return false;
            }

            $content->setVideoEditor($user);

            $this->persistAsanaLog(
                $content,
                'Délégation montage (Asana)',
                $this->journalFormatter->enrichDelegationDetail(
                    'Monteur',
                    $previous,
                    $user,
                    null,
                ),
            );

            return true;
        }

        $current = $content->getVideoCommunityManager();
        $currentGid = trim((string) ($current?->getAsanaUserGid() ?? ''));
        if ($currentGid === $assigneeGid) {
            return false;
        }

        $previous = $current;
        if ($user === null) {
            return false;
        }

        $content->setVideoCommunityManager($user);

        $this->persistAsanaLog(
            $content,
            'Délégation CM (Asana)',
            $this->journalFormatter->enrichDelegationDetail(
                'Community manager',
                $previous,
                $user,
                null,
            ),
        );

        return true;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function syncMontageDueOn(Content $content, array $task): bool
    {
        $dueOn = trim((string) ($task['due_on'] ?? ''));
        if ($dueOn === '') {
            return false;
        }

        try {
            $newDue = new \DateTimeImmutable($dueOn);
        } catch (\Throwable) {
            return false;
        }

        $stored = $content->getAsanaMontageDueOn();
        if ($stored !== null && $stored->format('Y-m-d') === $newDue->format('Y-m-d')) {
            return false;
        }

        $modifiedAt = $this->parseTaskModifiedAt($task);
        $lastPushed = $content->getAsanaMontageDueOnLastPushedAt();
        if ($lastPushed !== null && $modifiedAt !== null && $modifiedAt <= $lastPushed) {
            return false;
        }

        $oldLabel = $stored?->format('d/m/Y') ?? '—';
        $content->setAsanaMontageDueOn($newDue);

        $this->persistAsanaLog(
            $content,
            'Échéance montage Asana modifiée',
            $this->journalFormatter->enrichDateChangeDetail(
                'Échéance montage',
                $oldLabel,
                $newDue->format('d/m/Y'),
                null,
                'Asana',
            ),
        );

        return true;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function syncShootingAssignee(ShootingRequest $request, array $task): bool
    {
        $assignee = $task['assignee'] ?? null;
        if (!is_array($assignee)) {
            return false;
        }

        $assigneeGid = trim((string) ($assignee['gid'] ?? ''));
        if ($assigneeGid === '') {
            return false;
        }

        $user = $this->userRepository->findOneByAsanaUserGid($assigneeGid);
        if ($user === null) {
            return false;
        }

        $current = $request->getAssignedTo();
        if ($current !== null && $current->getId() === $user->getId()) {
            return false;
        }

        $request->setAssignedTo($user);
        $this->journalShootingVideos($request, 'Vidéaste tournage (Asana)', 'Assigné mis à jour depuis Asana : '.($user->getName() ?? '—'));

        return true;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function syncShootingDueOn(ShootingRequest $request, array $task): bool
    {
        $dueOn = trim((string) ($task['due_on'] ?? ''));
        if ($dueOn === '') {
            return false;
        }

        try {
            $newDate = new \DateTimeImmutable($dueOn);
        } catch (\Throwable) {
            return false;
        }

        $current = $request->getShootingDate();
        if ($current instanceof \DateTimeInterface && $current->format('Y-m-d') === $newDate->format('Y-m-d')) {
            return false;
        }

        $oldLabel = $current instanceof \DateTimeInterface ? $current->format('d/m/Y') : '—';
        $request->setShootingDate(\DateTime::createFromInterface($newDate));
        $this->journalShootingVideos(
            $request,
            'Date tournage Asana modifiée',
            sprintf('Date tournage : %s → %s (Asana)', $oldLabel, $newDate->format('d/m/Y')),
        );

        return true;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function syncShootingCompleted(ShootingRequest $request, array $task): bool
    {
        $completed = (bool) ($task['completed'] ?? false);
        if (!$completed) {
            if ($request->getAsanaTaskCompletedAt() !== null) {
                $request->setAsanaTaskCompletedAt(null);
                $this->journalShootingVideos(
                    $request,
                    'Tâche Asana tournage rouverte',
                    'La tâche tournage a été décochée dans Asana.',
                );

                return true;
            }

            return false;
        }

        if ($request->getAsanaTaskCompletedAt() !== null) {
            return false;
        }

        $request->setAsanaTaskCompletedAt(new \DateTimeImmutable());
        $this->journalShootingVideos(
            $request,
            'Tournage réalisé (Asana)',
            'Tâche Asana tournage cochée — aucun changement de statut vidéo automatique.',
        );

        return true;
    }

    private function journalShootingVideos(ShootingRequest $request, string $label, string $detail): void
    {
        foreach ($request->getVideos() as $video) {
            if (!$video instanceof Content) {
                continue;
            }
            $this->persistAsanaLog($video, $label, $detail);
        }
    }

    /**
     * @param array<string, mixed> $task
     */
    private function parseTaskModifiedAt(array $task): ?\DateTimeImmutable
    {
        $raw = trim((string) ($task['modified_at'] ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function persistAsanaLog(Content $content, string $label, string $detail): void
    {
        $log = new ContentActionLog();
        $log->setContent($content);
        $log->setActionType(ContentActionLog::TYPE_ASANA_SYNC);
        $log->setLabel($label);
        $log->setDetail($detail);
        $log->setUser(null);
        $this->entityManager->persist($log);
        $content->setUpdatedAt(new \DateTimeImmutable());
    }
}
