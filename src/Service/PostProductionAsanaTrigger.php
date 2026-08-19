<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\ContentActionLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Crée / gère la tâche Asana « Création post » pour les contenus non vidéo.
 */
final class PostProductionAsanaTrigger
{
    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly VideoAssigneeResolver $assigneeResolver,
        private readonly ContentFormatHelper $formatHelper,
        private readonly WorkflowJournalFormatter $journalFormatter,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly VideoMontageDueOnResolver $montageDueOnResolver,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{ok: bool, created: bool, message: string, gid: ?string}
     */
    public function ensureTask(Content $content, bool $flush = true): array
    {
        if ($this->formatHelper->isVideoContent($content)) {
            return ['ok' => false, 'created' => false, 'message' => 'Réservé aux posts non vidéo.', 'gid' => null];
        }
        if (!$this->asanaService->isEnabled()) {
            return ['ok' => false, 'created' => false, 'message' => 'Asana non configuré.', 'gid' => null];
        }

        $this->assigneeResolver->applyClientTeamDefaultsForForm($content);

        $existing = trim((string) ($content->getAsanaTaskGid() ?? ''));
        if ($existing !== '' && $this->asanaService->isTaskAccessible($existing)) {
            return [
                'ok' => true,
                'created' => false,
                'message' => 'Tâche Asana déjà liée.',
                'gid' => $existing,
            ];
        }

        if ($this->assigneeResolver->asanaGidForMontage($content) === null) {
            return [
                'ok' => false,
                'created' => false,
                'message' => 'Médiamaticien sans GID Asana (ou non assigné).',
                'gid' => null,
            ];
        }

        $client = $content->getClient();
        if ($client === null || trim((string) ($client->getAsanaProjectGid() ?? '')) === '') {
            return [
                'ok' => false,
                'created' => false,
                'message' => 'Projet Asana manquant sur le client.',
                'gid' => null,
            ];
        }

        if ($content->getId() === null) {
            return ['ok' => false, 'created' => false, 'message' => 'Enregistre le contenu avant.', 'gid' => null];
        }

        if ($content->getAsanaMontageDueOn() === null && $content->getScheduledDate() !== null) {
            $content->setAsanaMontageDueOn(
                $this->montageDueOnResolver->defaultFromPublication($content->getScheduledDate()),
            );
        }

        $ficheUrl = $this->urlGenerator->generate(
            'app_content_edit',
            ['id' => $content->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $fallback = getenv('ASANA_FALLBACK_ASSIGNEE_GID');
        $fallback = $fallback === false ? null : (string) $fallback;

        $gid = $this->asanaService->createTaskForPostProduction($content, $ficheUrl, $fallback);
        if ($gid === null) {
            return [
                'ok' => false,
                'created' => false,
                'message' => 'Échec création Asana (API / projet / droits).',
                'gid' => null,
            ];
        }

        $content->setAsanaTaskGid($gid);
        if ($content->getAsanaMontageDueOn() !== null && $content->getAsanaMontageDueOnLastPushedAt() === null) {
            $content->markAsanaMontageDueOnPushedFromLucy();
        }

        $user = $this->security->getUser();
        $actor = $user instanceof User ? $user : null;
        $log = new ContentActionLog();
        $log->setContent($content);
        $log->setActionType(ContentActionLog::TYPE_ASANA_SYNC);
        $log->setLabel('Tâche Asana création post créée');
        $log->setDetail(implode("\n", [
            'Tâche Asana : '.$gid,
            'Pour : '.($content->getVideoEditor()?->getName() ?? '—'),
            'Titre Asana : Création post - '.(trim((string) $content->getTitle()) !== '' ? $content->getTitle() : 'Sans titre'),
            'Par : '.$this->journalFormatter->formatActor($actor),
        ]));
        $log->setUser($actor);
        $this->entityManager->persist($log);
        $content->setUpdatedAt(new \DateTimeImmutable());

        if ($flush) {
            $this->entityManager->flush();
        }

        return [
            'ok' => true,
            'created' => true,
            'message' => 'Tâche Asana créée.',
            'gid' => $gid,
        ];
    }

    public function completeIfOpen(Content $content): bool
    {
        $gid = trim((string) ($content->getAsanaTaskGid() ?? ''));
        if ($gid === '' || !$this->asanaService->isEnabled()) {
            return false;
        }

        $task = $this->asanaService->fetchTask($gid);
        if ($task === null || !empty($task['completed'])) {
            return false;
        }

        return $this->asanaService->completeTask($gid);
    }
}
