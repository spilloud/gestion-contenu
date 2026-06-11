<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\ShootingRequest;
use App\Repository\StatusRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ShootingRequestDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StatusRepository $statusRepository,
        private readonly AsanaService $asanaService,
        private readonly ContentWorkflowService $contentWorkflowService,
    ) {
    }

    /**
     * @return array{ok: bool, message?: string, asanaDeleted: bool, videosReset: int}
     */
    public function delete(ShootingRequest $request): array
    {
        $plannedStatus = $this->statusRepository->findOneByName('Tournage à prévoir');
        if ($plannedStatus === null) {
            return ['ok' => false, 'message' => 'Statut « Tournage à prévoir » introuvable.', 'asanaDeleted' => false, 'videosReset' => 0];
        }

        $asanaGid = trim((string) ($request->getAsanaTaskGid() ?? ''));
        $asanaDeleted = false;
        if ($asanaGid !== '') {
            $asanaDeleted = $this->asanaService->deleteTask($asanaGid);
        }

        $videosReset = 0;
        foreach ($request->getVideos()->toArray() as $video) {
            if (!$video instanceof Content) {
                continue;
            }
            $current = $video->getStatus()?->getName() ?? '';
            if ($current !== $plannedStatus->getName()) {
                $this->contentWorkflowService->applyManualStatusChange($video, $plannedStatus, 'suppression_demande_tournage');
            }
            ++$videosReset;
        }

        $this->entityManager->remove($request);
        $this->entityManager->flush();

        return ['ok' => true, 'asanaDeleted' => $asanaDeleted, 'videosReset' => $videosReset];
    }
}
