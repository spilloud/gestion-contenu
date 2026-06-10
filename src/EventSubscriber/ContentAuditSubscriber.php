<?php

namespace App\EventSubscriber;

use App\Entity\Content;
use App\Entity\ContentActionLog;
use App\Entity\User;
use App\Service\ContentWorkflowService;
use App\Service\VideoAsanaAssigneeSync;
use App\Service\WorkflowJournalFormatter;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ContentAuditSubscriber
{
    /** @var list<array{content: Content, type: string, previousUser: ?User, nextUser: ?User}> */
    private array $asanaSyncQueue = [];

    public function __construct(
        private readonly ContentWorkflowService $workflowService,
        private readonly VideoAsanaAssigneeSync $videoAsanaAssigneeSync,
        private readonly WorkflowJournalFormatter $journalFormatter,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Content) {
            return;
        }

        if ($this->isWorkflowRequest($entity)) {
            return;
        }

        $changeSet = $args->getEntityChangeSet();
        $actor = $this->currentUser();

        if (isset($changeSet['status'])) {
            [$oldStatus, $newStatus] = $changeSet['status'];
            $oldName = $oldStatus?->getName() ?? '—';
            $newName = $newStatus?->getName() ?? '—';
            if ($oldName !== $newName) {
                $this->workflowService->logFieldChange(
                    $entity,
                    ContentActionLog::TYPE_MANUAL_STATUS,
                    'Statut modifié (fiche)',
                    $this->journalFormatter->enrichTransitionDetail($entity, $oldName, $newName, $actor),
                    false,
                );
            }
        }

        if (isset($changeSet['scheduledDate'])) {
            [$old, $new] = $changeSet['scheduledDate'];
            $this->workflowService->logFieldChange(
                $entity,
                ContentActionLog::TYPE_SCHEDULED_DATE_CHANGED,
                'Date de publication modifiée',
                $this->journalFormatter->enrichDateChangeDetail(
                    'Publication',
                    $old instanceof \DateTimeInterface ? $old->format('d/m/Y') : '—',
                    $new instanceof \DateTimeInterface ? $new->format('d/m/Y') : '—',
                    $actor,
                    'Fiche vidéo',
                ),
                false,
            );
        }

        if (isset($changeSet['videoEditor'])) {
            [$old, $new] = $changeSet['videoEditor'];
            $this->logUserChange($entity, 'Délégation montage', 'Monteur', $old, $new, ContentActionLog::TYPE_EDITOR_CHANGED, $actor);
            $this->asanaSyncQueue[] = [
                'content' => $entity,
                'type' => 'montage',
                'previousUser' => $old,
                'nextUser' => $new,
            ];
        }

        if (isset($changeSet['videoCommunityManager'])) {
            [$old, $new] = $changeSet['videoCommunityManager'];
            $this->logUserChange($entity, 'Délégation CM', 'Community manager', $old, $new, ContentActionLog::TYPE_CM_USER_CHANGED, $actor);
            $this->asanaSyncQueue[] = [
                'content' => $entity,
                'type' => 'cm',
                'previousUser' => $old,
                'nextUser' => $new,
            ];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->asanaSyncQueue === []) {
            return;
        }

        $queue = $this->asanaSyncQueue;
        $this->asanaSyncQueue = [];

        foreach ($queue as $job) {
            match ($job['type']) {
                'montage' => $this->videoAsanaAssigneeSync->syncMontageAssigneeIfChanged(
                    $job['content'],
                    $job['previousUser'],
                    $job['nextUser'],
                ),
                'cm' => $this->videoAsanaAssigneeSync->syncSubtitlesAfterCommunityManagerChange(
                    $job['content'],
                    $job['previousUser'],
                    $job['nextUser'],
                ),
                default => null,
            };
        }
    }

    private function logUserChange(
        Content $entity,
        string $journalLabel,
        string $roleLabel,
        mixed $old,
        mixed $new,
        string $actionType,
        ?User $actor,
    ): void {
        $oldUser = $old instanceof User ? $old : null;
        $newUser = $new instanceof User ? $new : null;
        if ($oldUser?->getId() === $newUser?->getId()) {
            return;
        }

        $this->workflowService->logFieldChange(
            $entity,
            $actionType,
            $journalLabel,
            $this->journalFormatter->enrichDelegationDetail($roleLabel, $oldUser, $newUser, $actor),
            false,
        );
    }

    private function isWorkflowRequest(Content $content): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || $content->getId() === null) {
            return false;
        }

        return (bool) $request->attributes->get('_content_workflow_handled_'.$content->getId());
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
