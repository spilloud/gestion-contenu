<?php

namespace App\EventSubscriber;

use App\Entity\Content;
use App\Entity\ContentActionLog;
use App\Entity\User;
use App\Service\ContentWorkflowService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::preUpdate)]
final class ContentAuditSubscriber
{
    public function __construct(
        private readonly ContentWorkflowService $workflowService,
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
            $this->workflowService->logFieldChange(
                $entity,
                ContentActionLog::TYPE_EDITOR_CHANGED,
                'Monteur modifié',
                sprintf(
                    '%s → %s',
                    $old instanceof User ? ($old->getName() ?? '—') : '—',
                    $new instanceof User ? ($new->getName() ?? '—') : '—',
                ),
                false,
            );
        }
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
