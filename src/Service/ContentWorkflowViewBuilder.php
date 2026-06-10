<?php

namespace App\Service;

use App\Entity\Content;
use App\Repository\ContentActionLogRepository;
use App\Workflow\ContentWorkflowRegistry;

final class ContentWorkflowViewBuilder
{
    public function __construct(
        private readonly ContentWorkflowRegistry $contentWorkflowRegistry,
        private readonly ContentActionLogRepository $contentActionLogRepository,
        private readonly WorkflowJournalFormatter $journalFormatter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Content $content): array
    {
        $journey = [];
        foreach ($this->contentActionLogRepository->findVisibleJourneyForContent($content) as $log) {
            $journey[] = [
                'label' => $log->getLabel(),
                'detail' => $log->getDetail(),
                'detailLines' => $this->journalFormatter->splitDetailLines($log->getDetail()),
                'createdAt' => $log->getCreatedAt(),
                'userName' => $log->getUser()?->getName(),
                'actionType' => $log->getActionType(),
            ];
        }

        return [
            'workflow_actions' => $this->contentWorkflowRegistry->availableActions($content),
            'workflow_can_step_back' => $this->contentWorkflowRegistry->previousStatusName($content) !== null,
            'workflow_journey' => $journey,
            'workflow_phases' => $this->contentWorkflowRegistry->phasesFor($content),
            'workflow_phase_index' => $this->contentWorkflowRegistry->phaseIndexFor($content),
        ];
    }
}
