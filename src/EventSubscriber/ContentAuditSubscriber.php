<?php

namespace App\EventSubscriber;

use App\Entity\CommunityManager;
use App\Entity\Content;
use App\Entity\ContentActionLog;
use App\Entity\User;
use App\Service\ContentWorkflowService;
use App\Service\VideoAsanaAssigneeSync;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ContentAuditSubscriber
{
    /** @var list<array{content: Content, type: string, previousUser: ?User, nextUser: ?User, previousCm: ?CommunityManager, nextCm: ?CommunityManager}> */
    private array $asanaSyncQueue = [];

    public function __construct(
        private readonly ContentWorkflowService $workflowService,
        private readonly VideoAsanaAssigneeSync $videoAsanaAssigneeSync,
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

        if (isset($changeSet['status'])) {
            [$oldStatus, $newStatus] = $changeSet['status'];
            $oldName = $oldStatus?->getName() ?? '—';
            $newName = $newStatus?->getName() ?? '—';
            if ($oldName !== $newName) {
                $this->workflowService->logFieldChange(
                    $entity,
                    ContentActionLog::TYPE_MANUAL_STATUS,
                    'Statut modifié (fiche)',
                    sprintf('%s → %s', $oldName, $newName),
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
                sprintf(
                    '%s → %s',
                    $old instanceof \DateTimeInterface ? $old->format('d/m/Y') : '—',
                    $new instanceof \DateTimeInterface ? $new->format('d/m/Y') : '—',
                ),
                false,
            );
        }

        if (isset($changeSet['videoEditor'])) {
            [$old, $new] = $changeSet['videoEditor'];
            $this->logUserChange($entity, 'Monteur modifié', $old, $new, ContentActionLog::TYPE_EDITOR_CHANGED);
            $this->asanaSyncQueue[] = [
                'content' => $entity,
                'type' => 'montage',
                'previousUser' => $old,
                'nextUser' => $new,
                'previousCm' => null,
                'nextCm' => null,
            ];
        }

        if (isset($changeSet['videoCommunityManager'])) {
            [$old, $new] = $changeSet['videoCommunityManager'];
            $this->logCmChange($entity, $old, $new);
            $this->asanaSyncQueue[] = [
                'content' => $entity,
                'type' => 'cm',
                'previousUser' => null,
                'nextUser' => null,
                'previousCm' => $old,
                'nextCm' => $new,
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
                    $job['previousCm'],
                    $job['nextCm'],
                ),
                default => null,
            };
        }
    }

    private function logUserChange(Content $entity, string $label, mixed $old, mixed $new, string $actionType): void
    {
        $oldName = $old instanceof User ? ($old->getName() ?? '—') : '—';
        $newName = $new instanceof User ? ($new->getName() ?? '—') : '—';
        if ($oldName === $newName) {
            return;
        }

        $this->workflowService->logFieldChange(
            $entity,
            $actionType,
            $label,
            sprintf('%s → %s', $oldName, $newName),
            false,
        );
    }

    private function logCmChange(Content $entity, mixed $old, mixed $new): void
    {
        $oldName = $old instanceof CommunityManager ? ($old->getName() ?? '—') : '—';
        $newName = $new instanceof CommunityManager ? ($new->getName() ?? '—') : '—';
        if ($oldName === $newName) {
            return;
        }

        $this->workflowService->logFieldChange(
            $entity,
            ContentActionLog::TYPE_CM_USER_CHANGED,
            'Community manager modifiée',
            sprintf('%s → %s', $oldName, $newName),
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
}
