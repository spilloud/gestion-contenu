<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Content;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Crée la tâche Asana « suivi dérush » pour la CM après validation du dérush.
 */
final class DerushCmAsanaTrigger
{
    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly VideoAssigneeResolver $assigneeResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<Content> $derushedVideos Vidéos passées en montage lors de cette session dérush.
     */
    public function createFollowUpTask(Client $client, array $derushedVideos, ?string $globalRushesUrl): ?string
    {
        if ($derushedVideos === [] || !$this->asanaService->isEnabled()) {
            return null;
        }

        $videoUrls = [];
        foreach ($derushedVideos as $video) {
            if (!$video instanceof Content || $video->getId() === null) {
                continue;
            }
            $videoUrls[$video->getId()] = $this->urlGenerator->generate(
                'app_video_show',
                ['id' => $video->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        $fallback = getenv('ASANA_FALLBACK_ASSIGNEE_GID');
        $fallback = $fallback === false ? null : (string) $fallback;

        return $this->asanaService->createDerushFollowUpTaskForCommunityManager(
            $client,
            $derushedVideos,
            $globalRushesUrl,
            $videoUrls,
            $this->assigneeResolver->asanaGidForCommunityManager($client),
            $fallback,
        );
    }
}
