<?php

namespace App\Service;

use App\Entity\Content;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Crée la tâche Asana « montage vidéo » lorsque la vidéo passe en « Montage à faire »
 * (dérush, bouton workflow, changement de statut manuel).
 */
final class VideoMontageAsanaTrigger
{
    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly VideoAssigneeResolver $assigneeResolver,
        private readonly ContentFormatHelper $formatHelper,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Valide le GID stocké ou rattache une tâche Asana existante (ex. créée manuellement).
     *
     * @return string|null GID Asana utilisable
     */
    public function resolveMontageTaskLink(Content $content, bool $flush = false): ?string
    {
        if (!$this->formatHelper->isVideoContent($content) || !$this->asanaService->isEnabled()) {
            return $content->getAsanaTaskGid();
        }

        if ($content->getId() === null) {
            return $content->getAsanaTaskGid();
        }

        $videoUrl = $this->urlGenerator->generate(
            'app_video_show',
            ['id' => $content->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $stored = $content->getAsanaTaskGid();
        if ($stored !== null && $this->asanaService->isTaskAccessible($stored)) {
            $this->syncMontageDueFromAsanaIfUnset($content, $stored);

            return $stored;
        }

        $changed = false;
        if ($stored !== null) {
            $content->setAsanaTaskGid(null);
            $changed = true;
        }

        $found = $this->asanaService->findMontageTaskForVideo($content, $videoUrl);
        if ($found !== null) {
            $content->setAsanaTaskGid($found);
            $this->syncMontageDueFromAsanaIfUnset($content, $found);
            $changed = true;

            if ($flush) {
                $this->entityManager->flush();
            }

            return $found;
        }

        if ($changed && $flush) {
            $this->entityManager->flush();
        }

        return null;
    }

    /**
     * Applique le monteur/CM client si manquant, puis crée la tâche Asana montage si besoin.
     *
     * @return bool true si une nouvelle tâche Asana a été créée
     */
    public function ensureWhenMontageQueued(Content $content, bool $flush = true): bool
    {
        if (!$this->formatHelper->isVideoContent($content)) {
            return false;
        }
        if ($content->getStatus()?->getName() !== 'Montage à faire') {
            return false;
        }

        $this->assigneeResolver->applyClientTeamDefaultsForForm($content);

        if (!$this->asanaService->isEnabled()) {
            if ($flush) {
                $this->entityManager->flush();
            }

            return false;
        }

        $this->resolveMontageTaskLink($content, false);
        if ($content->getAsanaTaskGid()) {
            $this->pushMontageDueToAsanaIfSet($content, $content->getAsanaTaskGid());
            if ($flush) {
                $this->entityManager->flush();
            }

            return false;
        }

        $fallback = getenv('ASANA_FALLBACK_ASSIGNEE_GID');
        $fallback = $fallback === false ? null : (string) $fallback;

        $videoUrl = $this->urlGenerator->generate(
            'app_video_show',
            ['id' => $content->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $gid = $this->asanaService->createTaskForVideo($content, $videoUrl, $fallback);
        if ($gid === null) {
            if ($flush) {
                $this->entityManager->flush();
            }

            return false;
        }

        $content->setAsanaTaskGid($gid);
        if ($content->getAsanaMontageDueOn() === null) {
            $this->syncMontageDueFromAsanaIfUnset($content, $gid);
        }
        if ($flush) {
            $this->entityManager->flush();
        }

        return true;
    }

    private function syncMontageDueFromAsanaIfUnset(Content $content, string $taskGid): void
    {
        if ($content->getAsanaMontageDueOn() !== null) {
            return;
        }

        $task = $this->asanaService->fetchTask($taskGid);
        if (!is_array($task) || empty($task['due_on'])) {
            return;
        }

        try {
            $content->setAsanaMontageDueOn(new \DateTimeImmutable((string) $task['due_on']));
        } catch (\Throwable) {
        }
    }

    private function pushMontageDueToAsanaIfSet(Content $content, string $taskGid): void
    {
        $due = $content->getAsanaMontageDueOn();
        if ($due === null) {
            return;
        }

        $this->asanaService->updateTaskDueOn($taskGid, $due);
    }
}
