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
            $detailLines = $this->journalFormatter->splitDetailLines($log->getDetail());
            $presented = $this->journalFormatter->presentJournalEntry(
                $log->getActionType(),
                $log->getUser()?->getName(),
                $detailLines,
            );

            $journey[] = [
                'label' => $log->getLabel(),
                'detail' => $log->getDetail(),
                'detailLines' => $presented['facts'],
                'actor' => $presented['actor'],
                'channel' => $presented['channel'],
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
