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
     * Préremplit monteur et CM depuis le client lorsque la fiche n'a pas de délégation explicite.
     */
    public function applyClientTeamDefaultsForForm(Content $content): void
    {
        if ($content->getVideoCommunityManager() === null) {
            $fromLegacy = $this->communityManagerFromLegacyUser($content->getVideoCmUser());
            if ($fromLegacy !== null) {
                $content->setVideoCommunityManager($fromLegacy);
            }
        }

        $client = $content->getClient();
        if ($client === null) {
            return;
        }

        if ($content->getVideoEditor() === null && $client->getEditor() !== null) {
            $content->setVideoEditor($client->getEditor());
        }

        if ($content->getVideoCommunityManager() === null && $client->getCommunityManager() !== null) {
            $content->setVideoCommunityManager($client->getCommunityManager());
        }
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
        $fromDelegated = $this->asanaGidFromCommunityManager($content->getVideoCommunityManager());
        if ($fromDelegated !== null) {
            return $fromDelegated;
        }

        $cmGid = $content->getVideoCmUser()?->getAsanaUserGid();
        if ($cmGid !== null && trim($cmGid) !== '') {
            return trim($cmGid);
        }

        $reviewerGid = $content->getVideoSubtitlesReviewer()?->getAsanaUserGid();
        if ($reviewerGid !== null && trim($reviewerGid) !== '') {
            return trim($reviewerGid);
        }

        $fromClient = $this->asanaGidFromCommunityManager($content->getClient()?->getCommunityManager());
        if ($fromClient !== null) {
            return $fromClient;
        }

        $fallback = getenv('ASANA_FALLBACK_ASSIGNEE_GID');

        return $fallback !== false && trim((string) $fallback) !== '' ? trim((string) $fallback) : null;
    }

    public function displayNameForCm(Content $content): string
    {
        if ($content->getVideoCommunityManager() !== null) {
            return $content->getVideoCommunityManager()->getName() ?? '—';
        }

        return $content->getClient()?->getCommunityManager()?->getName() ?? '—';
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
