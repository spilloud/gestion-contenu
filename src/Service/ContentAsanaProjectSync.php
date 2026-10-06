<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Content;

/**
 * Fait suivre les tâches Asana d'un contenu lorsqu'il change de client.
 *
 * Sans ça, déplacer une vidéo d'un client à l'autre dans Lucy laissait ses
 * tâches dans le projet Asana de l'ancien client.
 */
final class ContentAsanaProjectSync
{
    public function __construct(private readonly AsanaService $asanaService)
    {
    }

    public function syncAfterClientChange(Content $content, ?Client $previous, ?Client $next): void
    {
        if (!$this->asanaService->isEnabled()) {
            return;
        }

        $targetGid = trim((string) ($next?->getAsanaProjectGid() ?? ''));
        $previousGid = trim((string) ($previous?->getAsanaProjectGid() ?? ''));
        if ($targetGid === '' || $targetGid === $previousGid) {
            return;
        }

        foreach ($this->taskGids($content) as $taskGid) {
            if (!$this->asanaService->addTaskToProject($taskGid, $targetGid)) {
                continue;
            }
            // On ne retire que l'ancien projet client : une tâche peut
            // légitimement appartenir à d'autres projets transverses.
            if ($previousGid !== '') {
                $this->asanaService->removeTaskFromProject($taskGid, $previousGid);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function taskGids(Content $content): array
    {
        $gids = [];
        foreach ([$content->getAsanaTaskGid(), $content->getAsanaSubtitlesTaskGid()] as $gid) {
            $gid = trim((string) ($gid ?? ''));
            if ($gid !== '') {
                $gids[] = $gid;
            }
        }

        return array_values(array_unique($gids));
    }
}
