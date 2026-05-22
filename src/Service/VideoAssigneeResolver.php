<?php

namespace App\Service;

use App\Entity\CommunityManager;
use App\Entity\Content;
use App\Entity\User;
use App\Repository\CommunityManagerRepository;
use App\Repository\UserRepository;

/**
 * Résout les gid Asana pour montage et relecture sous-titres.
 */
final class VideoAssigneeResolver
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CommunityManagerRepository $communityManagerRepository,
    ) {
    }

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
     * Préremplit monteur et CM depuis le client (comme le monteur sur la fiche vidéo).
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
        if ($clientCm !== null) {
            if ($this->shouldAlignCommunityManagerWithClient($content, $clientCm)) {
                $content->setVideoCommunityManager($clientCm);
            }

            return;
        }

        if ($content->getVideoCommunityManager() === null) {
            $fromLegacy = $this->communityManagerFromLegacyUser($content->getVideoCmUser());
            if ($fromLegacy !== null) {
                $content->setVideoCommunityManager($fromLegacy);
            }
        }
    }

    public function resolveCommunityManagerForDisplay(Content $content): ?CommunityManager
    {
        $clientCm = $content->getClient()?->getCommunityManager();
        if ($clientCm !== null) {
            return $clientCm;
        }

        return $content->getVideoCommunityManager()
            ?? $this->communityManagerFromLegacyUser($content->getVideoCmUser());
    }

    private function shouldAlignCommunityManagerWithClient(Content $content, CommunityManager $clientCm): bool
    {
        $current = $content->getVideoCommunityManager();
        if ($current === null) {
            return true;
        }

        return $current->getId() !== $clientCm->getId();
    }

    private function communityManagerFromLegacyUser(?User $user): ?CommunityManager
    {
        if ($user === null) {
            return null;
        }

        $email = $user->getEmail();
        if ($email === null || trim($email) === '') {
            return null;
        }

        return $this->communityManagerRepository->findOneByEmailCaseInsensitive(trim($email));
    }

    public function asanaGidForSubtitlesReview(Content $content): ?string
    {
        $fromCm = $this->asanaGidFromCommunityManager($this->resolveCommunityManagerForDisplay($content));
        if ($fromCm !== null) {
            return $fromCm;
        }

        $cmGid = $content->getVideoCmUser()?->getAsanaUserGid();
        if ($cmGid !== null && trim($cmGid) !== '') {
            return trim($cmGid);
        }

        $reviewerGid = $content->getVideoSubtitlesReviewer()?->getAsanaUserGid();
        if ($reviewerGid !== null && trim($reviewerGid) !== '') {
            return trim($reviewerGid);
        }

        $fallback = getenv('ASANA_FALLBACK_ASSIGNEE_GID');

        return $fallback !== false && trim((string) $fallback) !== '' ? trim((string) $fallback) : null;
    }

    public function displayNameForCm(Content $content): string
    {
        return $this->resolveCommunityManagerForDisplay($content)?->getName() ?? '—';
    }

    private function asanaGidFromCommunityManager(?CommunityManager $cm): ?string
    {
        if ($cm === null) {
            return null;
        }

        $email = $cm->getEmail();
        if ($email === null || trim($email) === '') {
            return null;
        }

        $user = $this->userRepository->findOneByEmailCaseInsensitive(trim($email));
        $gid = $user?->getAsanaUserGid();

        return $gid !== null && trim($gid) !== '' ? trim($gid) : null;
    }
}
