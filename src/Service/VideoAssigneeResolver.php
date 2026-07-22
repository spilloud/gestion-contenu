<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\User;

/**
 * Résout les gid Asana pour montage et relecture sous-titres.
 */
final class VideoAssigneeResolver
{
    public function asanaGidForMontage(Content $content): ?string
    {
        $gid = $content->getVideoEditor()?->getAsanaUserGid();
        if ($gid !== null && trim($gid) !== '') {
            return trim($gid);
        }

        $gid = $content->getClient()?->getEditor()?->getAsanaUserGid();

        return $gid !== null && trim($gid) !== '' ? trim($gid) : null;
    }

    /**
     * Préremplit monteur et CM depuis le client lorsque la fiche n'a pas de délégation explicite.
     */
    public function applyClientTeamDefaultsForForm(Content $content): void
    {
        $client = $content->getClient();
        if ($client === null) {
            return;
        }

        if ($content->getVideoEditor() === null && $client->getEditor() !== null) {
            $content->setVideoEditor($client->getEditor());
        }

        $clientCm = $client->getCommunityManager();
        if ($clientCm !== null && $this->shouldAlignCommunityManagerWithClient($content, $clientCm)) {
            $content->setVideoCommunityManager($clientCm);
        }
    }

    public function resolveCommunityManagerForDisplay(Content $content): ?User
    {
        return $content->getVideoCommunityManager() ?? $content->getClient()?->getCommunityManager();
    }

    private function shouldAlignCommunityManagerWithClient(Content $content, User $clientCm): bool
    {
        $current = $content->getVideoCommunityManager();
        if ($current === null) {
            return true;
        }

        return $current->getId() !== $clientCm->getId();
    }

    public function asanaGidForSubtitlesReview(Content $content): ?string
    {
        $gid = $this->resolveCommunityManagerForDisplay($content)?->getAsanaUserGid();
        if ($gid !== null && trim($gid) !== '') {
            return trim($gid);
        }

        $reviewerGid = $content->getVideoSubtitlesReviewer()?->getAsanaUserGid();
        if ($reviewerGid !== null && trim($reviewerGid) !== '') {
            return trim($reviewerGid);
        }

        $fallback = getenv('ASANA_FALLBACK_ASSIGNEE_GID');

        return $fallback !== false && trim((string) $fallback) !== '' ? trim((string) $fallback) : null;
    }

    public function asanaGidForCommunityManager(\App\Entity\Client $client): ?string
    {
        $gid = $client->getCommunityManager()?->getAsanaUserGid();
        if ($gid !== null && trim($gid) !== '') {
            return trim($gid);
        }

        $fallback = getenv('ASANA_FALLBACK_ASSIGNEE_GID');

        return $fallback !== false && trim((string) $fallback) !== '' ? trim((string) $fallback) : null;
    }

    public function displayNameForCm(Content $content): string
    {
        return $this->resolveCommunityManagerForDisplay($content)?->getName() ?? '—';
    }
}
