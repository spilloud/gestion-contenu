<?php

namespace App\Service;

use App\Entity\Content;
use App\Repository\UserRepository;

/**
 * Résout les gid Asana pour montage et relecture sous-titres.
 */
final class VideoAssigneeResolver
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function asanaGidForMontage(Content $content): ?string
    {
        $gid = $content->getVideoEditor()?->getAsanaUserGid();

        return $gid !== null && trim($gid) !== '' ? trim($gid) : null;
    }

    public function asanaGidForSubtitlesReview(Content $content): ?string
    {
        $reviewerGid = $content->getVideoSubtitlesReviewer()?->getAsanaUserGid();
        if ($reviewerGid !== null && trim($reviewerGid) !== '') {
            return trim($reviewerGid);
        }

        $cmGid = $content->getVideoCmUser()?->getAsanaUserGid();
        if ($cmGid !== null && trim($cmGid) !== '') {
            return trim($cmGid);
        }

        $client = $content->getClient();
        $cmEmail = $client?->getCommunityManager()?->getEmail();
        if ($cmEmail !== null && trim($cmEmail) !== '') {
            $cmUser = $this->userRepository->findOneByEmailCaseInsensitive(trim($cmEmail));
            $fromClient = $cmUser?->getAsanaUserGid();
            if ($fromClient !== null && trim($fromClient) !== '') {
                return trim($fromClient);
            }
        }

        $fallback = getenv('ASANA_FALLBACK_ASSIGNEE_GID');

        return $fallback !== false && trim((string) $fallback) !== '' ? trim((string) $fallback) : null;
    }

    public function displayNameForCm(Content $content): string
    {
        if ($content->getVideoCmUser() !== null) {
            return $content->getVideoCmUser()->getName() ?? '—';
        }

        return $content->getClient()?->getCommunityManager()?->getName() ?? '—';
    }

    public function displayNameForSubtitlesReviewer(Content $content): string
    {
        if ($content->getVideoSubtitlesReviewer() !== null) {
            return $content->getVideoSubtitlesReviewer()->getName() ?? '—';
        }

        if ($content->getVideoCmUser() !== null) {
            return $content->getVideoCmUser()->getName().' (CM)';
        }

        return $content->getClient()?->getCommunityManager()?->getName() ?? '—';
    }
}
