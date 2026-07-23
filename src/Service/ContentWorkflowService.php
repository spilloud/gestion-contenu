<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\ContentActionLog;
use App\Entity\Status;
use App\Entity\User;
use App\Repository\StatusRepository;
use App\Workflow\ContentWorkflowRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContentWorkflowService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StatusRepository $statusRepository,
        private readonly ContentWorkflowRegistry $registry,
        private readonly ContentFormatHelper $formatHelper,
        private readonly AsanaService $asanaService,
        private readonly SubtitlesReviewAsanaTrigger $subtitlesReviewAsanaTrigger,
        private readonly VideoMontageAsanaTrigger $montageAsanaTrigger,
        private readonly VideoAssigneeResolver $assigneeResolver,
        private readonly WorkflowJournalFormatter $journalFormatter,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function logCreation(Content $content): void
    {
        $statusName = $content->getStatus()?->getName() ?? '—';
        $lines = ['Statut initial : '.$statusName, 'Par : '.$this->journalFormatter->formatActor($this->currentUser())];
        $this->persistLog(
            $content,
            ContentActionLog::TYPE_CREATED,
            'Contenu créé',
            implode("\n", $lines),
        );
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public function applyTransition(Content $content, string $actionId, bool $fromAsana = false): array
    {
        $transition = $this->registry->getTransition($actionId, $content);
        if ($transition === null) {
            return ['ok' => false, 'message' => 'Action inconnue.'];
        }

        $currentName = $content->getStatus()?->getName() ?? '';
        if (!in_array($currentName, $transition['from'], true)) {
            return ['ok' => false, 'message' => 'Cette action n\'est pas disponible à l\'étape actuelle.'];
        }

        $newStatus = $this->statusRepository->findOneByName($transition['to']);
        if ($newStatus === null) {
            return ['ok' => false, 'message' => sprintf('Statut « %s » introuvable (migration ?).', $transition['to'])];
        }

        $oldName = $currentName;
        $this->markWorkflowHandled($content);
        $content->setStatus($newStatus);
        $content->setUpdatedAt(new \DateTimeImmutable());

        foreach ($transition['effects'] as $effect) {
            $this->applyEffect($content, $effect, $fromAsana);
        }

        $this->persistLog(
            $content,
            ContentActionLog::TYPE_TRANSITION,
            $transition['label'],
            $this->journalFormatter->enrichTransitionDetail(
                $content,
                $oldName,
                $newStatus->getName() ?? '',
                $this->currentUser(),
            ),
        );

        $this->entityManager->flush();
        $this->afterStatusChange($content, $oldName, $newStatus->getName() ?? '');

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public function stepBack(Content $content): array
    {
        $previousName = $this->registry->previousStatusName($content);
        if ($previousName === null) {
            return ['ok' => false, 'message' => 'Impossible de reculer depuis cette étape.'];
        }

        $newStatus = $this->statusRepository->findOneByName($previousName);
        if ($newStatus === null) {
            return ['ok' => false, 'message' => 'Statut précédent introuvable.'];
        }

        $oldName = $content->getStatus()?->getName() ?? '—';
        $this->markWorkflowHandled($content);
        $content->setStatus($newStatus);
        $content->setUpdatedAt(new \DateTimeImmutable());

        $this->persistLog(
            $content,
            ContentActionLog::TYPE_STEP_BACK,
            'Recul d\'une étape',
            $this->journalFormatter->enrichTransitionDetail(
                $content,
                $oldName,
                $previousName,
                $this->currentUser(),
            ),
        );

        $this->entityManager->flush();
        $this->afterStatusChange($content, $oldName, $previousName);

        return ['ok' => true];
    }

    public function applyManualStatusChange(Content $content, Status $newStatus, ?string $source = 'manuel'): void
    {
        $oldName = $content->getStatus()?->getName() ?? '—';
        if ($oldName === ($newStatus->getName() ?? '')) {
            return;
        }

        $this->markWorkflowHandled($content);
        $content->setStatus($newStatus);
        $content->setUpdatedAt(new \DateTimeImmutable());

        $this->persistLog(
            $content,
            ContentActionLog::TYPE_MANUAL_STATUS,
            'Changement de statut (manuel)',
            $this->journalFormatter->enrichTransitionDetail(
                $content,
                $oldName,
                $newStatus->getName() ?? '',
                $this->currentUser(),
            )."\nSource : ".$source,
        );

        $this->entityManager->flush();
        $this->afterStatusChange($content, $oldName, $newStatus->getName() ?? '');
    }

    public function logFieldChange(Content $content, string $actionType, string $label, string $detail, bool $flush = true): void
    {
        $this->persistLog($content, $actionType, $label, $detail);
        if ($flush) {
            $this->entityManager->flush();
        }
    }

    private function markWorkflowHandled(Content $content): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && $content->getId() !== null) {
            $request->attributes->set('_content_workflow_handled_'.$content->getId(), true);
        }
    }

    private function applyEffect(Content $content, string $effect, bool $fromAsana = false): void
    {
        match ($effect) {
            'set_subtitles_yes' => $content->setVideoHasSubtitles(true),
            'set_subtitles_no' => $content->setVideoHasSubtitles(false),
            'complete_asana_montage' => $fromAsana ? null : $this->completeMontageAsanaTask($content),
            'complete_asana_subtitles' => $fromAsana ? null : $this->completeSubtitlesAsanaTask($content),
            'trigger_subtitles_asana' => null,
            default => null,
        };
    }

    private function completeSubtitlesAsanaTask(Content $content): void
    {
        $gid = trim((string) ($content->getAsanaSubtitlesTaskGid() ?? ''));
        if ($gid === '' || !$this->asanaService->isEnabled()) {
            return;
        }

        $user = $this->currentUser();
        $actor = $user instanceof User ? ($user->getName() ?? $user->getUserIdentifier()) : '—';
        $this->asanaService->addCommentToTask($gid, "Sous-titres validés (via Gestion des contenus).\nPar : @$actor");
        $this->asanaService->completeTask($gid);
    }

    private function completeMontageAsanaTask(Content $content): void
    {
        $gid = $this->montageAsanaTrigger->resolveMontageTaskLink($content, false);
        if (!$gid || !$this->asanaService->isEnabled()) {
            return;
        }

        $user = $this->currentUser();
        $actor = $user instanceof User ? ($user->getName() ?? $user->getUserIdentifier()) : '—';
        $this->asanaService->addCommentToTask($gid, "Montage terminé (via Gestion des contenus).\nPar : @$actor");
        $this->asanaService->completeTask($gid);
    }

    private function afterStatusChange(Content $content, string $from, string $to): void
    {
        if (!$this->formatHelper->isVideoContent($content) || !$this->asanaService->isEnabled()) {
            if ($this->formatHelper->isVideoContent($content)) {
                $this->subtitlesReviewAsanaTrigger->ensureWhenStatusIsSubtitlesReview($content);
            }

            return;
        }

        if ($to === 'Montage à faire') {
            $montageGidBefore = $content->getAsanaTaskGid();
            $this->montageAsanaTrigger->ensureWhenMontageQueued($content, false);
            $this->logAsanaSideEffect($content, $montageGidBefore, $content->getAsanaTaskGid(), 'montage');
        }

        $gid = $this->montageAsanaTrigger->resolveMontageTaskLink($content, false);
        if ($gid) {
            $user = $this->currentUser();
            $actor = $user instanceof User ? ($user->getName() ?? $user->getUserIdentifier()) : '—';
            $text = trim("Statut : $from → $to\nPar : @$actor");
            $this->asanaService->addCommentToTask($gid, $text);
        }

        $subtitlesGidBefore = $content->getAsanaSubtitlesTaskGid();
        $this->subtitlesReviewAsanaTrigger->ensureWhenStatusIsSubtitlesReview($content);
        $this->logAsanaSideEffect($content, $subtitlesGidBefore, $content->getAsanaSubtitlesTaskGid(), 'subtitles');

        $this->entityManager->flush();
    }

    private function logAsanaSideEffect(Content $content, ?string $beforeGid, ?string $afterGid, string $kind): void
    {
        if ($afterGid === null || $afterGid === $beforeGid) {
            return;
        }

        $assignee = $kind === 'montage'
            ? $content->getVideoEditor()
            : $this->assigneeResolver->resolveCommunityManagerForDisplay($content);

        $lines = [
            'Tâche Asana : '.$afterGid,
            'Pour : '.$this->journalFormatter->formatActor($assignee),
            'Par : '.$this->journalFormatter->formatActor($this->currentUser()),
        ];

        $this->persistLog(
            $content,
            ContentActionLog::TYPE_ASANA_SYNC,
            $kind === 'montage' ? 'Tâche Asana montage créée' : 'Tâche Asana relecture CM créée',
            implode("\n", $lines),
        );
    }

    private function persistLog(Content $content, string $type, string $label, ?string $detail): void
    {
        $log = new ContentActionLog();
        $log->setContent($content);
        $log->setActionType($type);
        $log->setLabel($label);
        $log->setDetail($detail);
        $log->setUser($this->currentUser());
        $this->entityManager->persist($log);
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
