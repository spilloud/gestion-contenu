<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\ContentActionLog;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronise Asana → Lucy (assignation, échéance) et journalise les changements.
 */
final class AsanaInboundSyncService
{
    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly ContentFormatHelper $formatHelper,
        private readonly WorkflowJournalFormatter $journalFormatter,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function syncContent(Content $content, bool $flush = true): bool
    {
        if (!$this->formatHelper->isVideoContent($content) || !$this->asanaService->isEnabled()) {
            return false;
        }

        $changed = $this->syncMontageTask($content) || $this->syncSubtitlesTask($content);

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

        return $this->syncAssigneeFromTask($content, $task, 'subtitles');
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

        $asanaName = trim((string) ($assignee['name'] ?? ''));
        $user = $this->userRepository->findOneByAsanaUserGid($assigneeGid);

        if ($kind === 'montage') {
            $current = $content->getVideoEditor();
            $currentGid = trim((string) ($current?->getAsanaUserGid() ?? ''));
            if ($currentGid === $assigneeGid) {
                return false;
            }

            $previous = $current;
            if ($user !== null) {
                $content->setVideoEditor($user);
            }

            $this->persistAsanaLog(
                $content,
                'Délégation montage (Asana)',
                $this->journalFormatter->enrichDelegationDetail(
                    'Monteur',
                    $previous,
                    $user ?? null,
                    null,
                ).($user === null && $asanaName !== '' ? "\nAssigné Asana : ".$asanaName : ''),
            );

            return true;
        }

        $current = $content->getVideoCommunityManager();
        $currentGid = trim((string) ($current?->getAsanaUserGid() ?? ''));
        if ($currentGid === $assigneeGid) {
            return false;
        }

        $previous = $current;
        if ($user !== null) {
            $content->setVideoCommunityManager($user);
        }

        $this->persistAsanaLog(
            $content,
            'Délégation CM (Asana)',
            $this->journalFormatter->enrichDelegationDetail(
                'Community manager',
                $previous,
                $user ?? null,
                null,
            ).($user === null && $asanaName !== '' ? "\nAssigné Asana : ".$asanaName : ''),
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
