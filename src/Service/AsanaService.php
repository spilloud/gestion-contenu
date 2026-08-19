<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\ShootingRequest;
use App\Repository\ContentRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AsanaService
{
    /** @var array<string, array<string, mixed>|null> */
    private array $taskFetchCache = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $taskStoriesCache = [];

    /** Compte technique Lucy (ne pas afficher comme auteur humain). */
    private const LUCY_ASANA_USER_GID = '1210382795264260';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly VideoMontageDueOnResolver $montageDueOnResolver,
        private readonly ContentRepository $contentRepository,
    ) {
    }

    public function isEnabled(): bool
    {
        $token = getenv('ASANA_ACCESS_TOKEN');

        return $token !== false && trim((string) $token) !== '';
    }

    /**
     * @return array<string, mixed>|null Données tâche Asana ou null si introuvable / inaccessible.
     */
    public function fetchTask(string $taskGid): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $taskGid = trim($taskGid);
        if ($taskGid === '') {
            return null;
        }

        if (array_key_exists($taskGid, $this->taskFetchCache)) {
            return $this->taskFetchCache[$taskGid];
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request(
                'GET',
                'https://app.asana.com/api/1.0/tasks/'.rawurlencode($taskGid).'?opt_fields=name,notes,assignee.name,assignee.gid,completed,completed_by.name,completed_by.gid,due_on,modified_at,permalink_url,projects.gid',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                    ],
                ],
            );
            if ($resp->getStatusCode() === 404) {
                $this->taskFetchCache[$taskGid] = null;

                return null;
            }
            $data = $resp->toArray(false);
        } catch (\Throwable) {
            $this->taskFetchCache[$taskGid] = null;

            return null;
        }

        $task = $data['data'] ?? null;
        $resolved = is_array($task) ? $task : null;
        $this->taskFetchCache[$taskGid] = $resolved;

        return $resolved;
    }

    public function isTaskAccessible(string $taskGid): bool
    {
        return $this->fetchTask($taskGid) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchTaskStories(string $taskGid): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $taskGid = trim($taskGid);
        if ($taskGid === '') {
            return [];
        }

        if (array_key_exists($taskGid, $this->taskStoriesCache)) {
            return $this->taskStoriesCache[$taskGid];
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request(
                'GET',
                'https://app.asana.com/api/1.0/tasks/'.rawurlencode($taskGid).'/stories',
                [
                    'headers' => ['Authorization' => 'Bearer '.$token],
                    'query' => [
                        'opt_fields' => 'created_at,created_by.name,created_by.gid,created_by.email,text,type,resource_subtype',
                    ],
                ],
            );
            if ($resp->getStatusCode() >= 400) {
                $this->taskStoriesCache[$taskGid] = [];

                return [];
            }
            $payload = $resp->toArray(false);
        } catch (\Throwable) {
            $this->taskStoriesCache[$taskGid] = [];

            return [];
        }

        $stories = [];
        foreach ($payload['data'] ?? [] as $story) {
            if (is_array($story)) {
                $stories[] = $story;
            }
        }

        $this->taskStoriesCache[$taskGid] = $stories;

        return $stories;
    }

    /**
     * Dernier auteur humain d'une story Asana (ex. assigned, due_date_changed, marked_complete).
     */
    public function resolveStoryActorName(string $taskGid, string $resourceSubtype): ?string
    {
        $stories = $this->fetchTaskStories($taskGid);
        for ($i = count($stories) - 1; $i >= 0; --$i) {
            $story = $stories[$i];
            if (($story['resource_subtype'] ?? '') !== $resourceSubtype) {
                continue;
            }
            $name = $this->humanActorNameFromStory($story);
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $task
     */
    public function resolveCompletedByName(array $task, ?string $taskGid = null): ?string
    {
        $completedBy = $task['completed_by'] ?? null;
        if (is_array($completedBy)) {
            $gid = trim((string) ($completedBy['gid'] ?? ''));
            $name = trim((string) ($completedBy['name'] ?? ''));
            if ($gid !== self::LUCY_ASANA_USER_GID && $name !== '' && strcasecmp($name, 'Lucy') !== 0) {
                return $name;
            }
        }

        $gid = trim((string) ($taskGid ?? $task['gid'] ?? ''));
        if ($gid === '') {
            return null;
        }

        return $this->resolveStoryActorName($gid, 'marked_complete');
    }

    /**
     * @param array<string, mixed> $story
     */
    private function humanActorNameFromStory(array $story): ?string
    {
        $createdBy = $story['created_by'] ?? null;
        if (!is_array($createdBy)) {
            return null;
        }

        $gid = trim((string) ($createdBy['gid'] ?? ''));
        $name = trim((string) ($createdBy['name'] ?? ''));
        if ($gid === self::LUCY_ASANA_USER_GID || strcasecmp($name, 'Lucy') === 0) {
            return null;
        }

        return $name !== '' ? $name : null;
    }

    /**
     * Cherche une tâche montage existante dans le projet client (création manuelle Asana incluse).
     */
    public function findMontageTaskForVideo(Content $content, string $videoUrl): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $client = $content->getClient();
        $projectGid = trim((string) ($client?->getAsanaProjectGid() ?? ''));
        if ($projectGid === '') {
            return null;
        }

        $bestGid = null;
        $bestScore = -1;

        foreach ($this->iterateProjectTasks($projectGid, ['name', 'notes', 'completed', 'gid']) as $task) {
            $score = $this->scoreMontageTaskMatch($task, $content, $videoUrl);
            if ($score < 0) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestGid = trim((string) ($task['gid'] ?? ''));
            }
        }

        return $bestGid !== '' ? $bestGid : null;
    }

    /**
     * Tâches « Suivi dérush » d'un projet client (historique + récentes).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function findDerushFollowUpTasksInProject(string $projectGid): \Generator
    {
        if (!$this->isEnabled()) {
            return;
        }

        $projectGid = trim($projectGid);
        if ($projectGid === '') {
            return;
        }

        foreach ($this->iterateProjectTasks($projectGid, ['name', 'notes', 'completed', 'gid'], '1970-01-01T00:00:00Z') as $task) {
            $name = trim((string) ($task['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $nameLower = mb_strtolower($name);
            if (!str_contains($nameLower, 'suivi') || (!str_contains($nameLower, 'dérush') && !str_contains($nameLower, 'derush'))) {
                continue;
            }
            yield $task;
        }
    }

    /**
     * Recherche de tâches dans le workspace (ex. rattrapage « Suivi dérush »).
     *
     * @return list<array<string, mixed>>
     */
    public function searchTasksInWorkspace(string $text, ?string $completedSince = '1970-01-01T00:00:00Z'): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));
        $workspaceGid = trim((string) (getenv('ASANA_WORKSPACE_GID') ?: ''));
        $text = trim($text);
        if ($workspaceGid === '' || $text === '') {
            return [];
        }

        $query = [
            'text' => $text,
            'opt_fields' => 'name,notes,completed,gid,projects',
            'limit' => 100,
        ];
        if ($completedSince !== null) {
            $query['completed_since'] = $completedSince;
        }

        try {
            $resp = $this->httpClient->request(
                'GET',
                'https://app.asana.com/api/1.0/workspaces/'.rawurlencode($workspaceGid).'/tasks/search',
                [
                    'headers' => ['Authorization' => 'Bearer '.$token],
                    'query' => $query,
                ],
            );
            if ($resp->getStatusCode() >= 400) {
                return [];
            }
            $payload = $resp->toArray(false);
        } catch (\Throwable) {
            return [];
        }

        $tasks = [];
        foreach ($payload['data'] ?? [] as $task) {
            if (is_array($task)) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    /**
     * Crée une tâche Asana pour une vidéo, si configuré.
     * Retourne le task gid (string) ou null si non créé.
     */
    public function createTaskForVideo(
        Content $content,
        string $videoUrl,
        ?string $fallbackAssigneeGid,
        bool $skipExistingTaskLookup = false,
    ): ?string {
        if (!$this->isEnabled()) {
            return null;
        }

        $stored = $content->getAsanaTaskGid();
        if ($stored !== null && $this->isTaskAccessible($stored)) {
            return $stored;
        }

        if (!$skipExistingTaskLookup) {
            $existing = $this->findMontageTaskForVideo($content, $videoUrl);
            if ($existing !== null && $this->contentRepository->isAsanaTaskGidLinkedToOtherContent($existing, $content->getId())) {
                $existing = null;
            }
            if ($existing !== null) {
                return $existing;
            }
        }

        $taskData = $this->buildMontageTaskPayload($content, $videoUrl, $fallbackAssigneeGid);
        if ($taskData === null) {
            return null;
        }

        return $this->postMontageTask($taskData);
    }

    /**
     * Crée plusieurs tâches montage en parallèle (dérush).
     *
     * @param list<Content>        $contents
     * @param array<int, string>   $videoUrls
     *
     * @return array<int, string> contentId => taskGid
     */
    public function createMontageTasksInParallel(array $contents, array $videoUrls, ?string $fallbackAssigneeGid): array
    {
        if (!$this->isEnabled() || $contents === []) {
            return [];
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));
        /** @var list<array{content: Content, response: \Symfony\Contracts\HttpClient\ResponseInterface}> $batch */
        $batch = [];

        foreach ($contents as $content) {
            if (!$content instanceof Content || $content->getId() === null) {
                continue;
            }

            $videoUrl = $videoUrls[$content->getId()] ?? '';
            if ($videoUrl === '') {
                continue;
            }

            $taskData = $this->buildMontageTaskPayload($content, $videoUrl, $fallbackAssigneeGid);
            if ($taskData === null) {
                continue;
            }

            $batch[] = [
                'content' => $content,
                'response' => $this->httpClient->request('POST', 'https://app.asana.com/api/1.0/tasks', [
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => ['data' => $taskData],
                ]),
            ];
        }

        if ($batch === []) {
            return [];
        }

        $created = [];
        $responses = array_map(static fn (array $item) => $item['response'], $batch);

        foreach ($this->httpClient->stream($responses) as $response => $chunk) {
            if (!method_exists($chunk, 'isLast') || !$chunk->isLast()) {
                continue;
            }

            $content = null;
            foreach ($batch as $item) {
                if ($item['response'] === $response) {
                    $content = $item['content'];
                    break;
                }
            }
            if (!$content instanceof Content || $content->getId() === null) {
                continue;
            }

            try {
                if ($response->getStatusCode() >= 400) {
                    continue;
                }
                $data = $response->toArray(false);
            } catch (\Throwable) {
                continue;
            }

            $gid = $data['data']['gid'] ?? null;
            if (!is_string($gid) || trim($gid) === '') {
                continue;
            }

            $created[$content->getId()] = trim($gid);
        }

        return $created;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildMontageTaskPayload(Content $content, string $videoUrl, ?string $fallbackAssigneeGid): ?array
    {
        $workspaceGid = trim((string) (getenv('ASANA_WORKSPACE_GID') ?: ''));
        $client = $content->getClient();
        $projectGid = trim((string) ($client?->getAsanaProjectGid() ?? ''));

        if ($workspaceGid === '' || $projectGid === '') {
            return null;
        }

        $assigneeGid = null;
        $editor = $content->getVideoEditor() ?? $client?->getEditor();
        if ($editor && $editor->getAsanaUserGid()) {
            $assigneeGid = $editor->getAsanaUserGid();
        } elseif ($fallbackAssigneeGid !== null && trim($fallbackAssigneeGid) !== '') {
            $assigneeGid = trim($fallbackAssigneeGid);
        }

        $clientName = $client?->getName() ?? 'Sans client';
        $videoTitle = trim((string) ($content->getTitle() ?? ''));
        $name = ($videoTitle !== '' ? $videoTitle : 'Vidéo').' - Montage vidéo';

        $dueAt = $this->montageDueOnResolver->resolveForContent($content);
        $dueOn = $dueAt->format('Y-m-d');
        $dueLabelFr = $dueAt->format('d/m/Y');

        $links = array_filter([
            $content->getVideoRushesUrl() ? 'Rushs (KDrive) : '.$content->getVideoRushesUrl() : null,
            $content->getVideoEditUrl() ? 'Montage (KDrive) : '.$content->getVideoEditUrl() : null,
            $content->getVideoFinalUrl() ? 'Final (KDrive) : '.$content->getVideoFinalUrl() : null,
            $content->getVideoThumbnailUrl() ? 'Miniature (KDrive) : '.$content->getVideoThumbnailUrl() : null,
            $content->getVideoSubmagicUrl() ? 'SubMagic : '.$content->getVideoSubmagicUrl() : null,
        ]);

        $notes = implode("\n", array_filter([
            'Vidéo créée depuis Gestion des contenus.',
            'Client : '.$clientName,
            'Échéance montage souhaitée : le '.$dueLabelFr.' — due_on Asana '.$dueOn.'.',
            'Sous-titres : '.(($content->getVideoHasSubtitles() ?? false) ? 'Oui' : 'Non'),
            '',
            $links !== [] ? "Liens :\n- ".implode("\n- ", $links) : null,
            $links !== [] ? '' : null,
            'Outil (fiche vidéo) : '.$videoUrl,
        ]));

        $taskData = [
            'name' => $name,
            'notes' => $notes,
            'workspace' => $workspaceGid,
            'projects' => [$projectGid],
            'due_on' => $dueOn,
        ];
        if ($assigneeGid !== null && trim((string) $assigneeGid) !== '') {
            $taskData['assignee'] = trim((string) $assigneeGid);
        }

        return $taskData;
    }

    /**
     * @param array<string, mixed> $taskData
     */
    private function postMontageTask(array $taskData): ?string
    {
        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request('POST', 'https://app.asana.com/api/1.0/tasks', [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
                'json' => ['data' => $taskData],
            ]);
            $data = $resp->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $gid = $data['data']['gid'] ?? null;
        if (!is_string($gid) || trim($gid) === '') {
            return null;
        }

        return trim($gid);
    }

    /**
     * Ajoute un commentaire (story) sur une tâche Asana existante.
     */
    public function addCommentToTask(string $taskGid, string $text): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $taskGid = trim($taskGid);
        if ($taskGid === '' || trim($text) === '') {
            return false;
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request('POST', 'https://app.asana.com/api/1.0/tasks/'.$taskGid.'/stories', [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'data' => [
                        'text' => $text,
                    ],
                ],
            ]);

            $status = $resp->getStatusCode();
            return $status >= 200 && $status < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Crée une tâche "relecture sous-titres" pour une vidéo.
     * Retourne le task gid (string) ou null si non créé.
     */
    public function createSubtitlesReviewTaskForVideo(
        Content $content,
        string $videoUrl,
        ?string $assigneeGid,
        ?string $fallbackAssigneeGid,
    ): ?string {
        if (!$this->isEnabled()) {
            return null;
        }

        if ($content->getAsanaSubtitlesTaskGid()) {
            return $content->getAsanaSubtitlesTaskGid();
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));
        $workspaceGid = trim((string) (getenv('ASANA_WORKSPACE_GID') ?: ''));
        $client = $content->getClient();
        $projectGid = $client ? (string) ($client->getAsanaProjectGid() ?? '') : '';
        $projectGid = trim($projectGid);

        if ($workspaceGid === '' || $projectGid === '') {
            return null;
        }

        $assigneeGid = $assigneeGid !== null && trim($assigneeGid) !== '' ? trim($assigneeGid)
            : ($fallbackAssigneeGid !== null && trim($fallbackAssigneeGid) !== '' ? trim($fallbackAssigneeGid) : null);

        $clientName = $client?->getName() ?? 'Sans client';
        $videoTitle = trim((string) ($content->getTitle() ?? ''));
        $name = ($videoTitle !== '' ? $videoTitle : 'Vidéo').' - Relecture de sous-titres';

        // Délai relecture sous-titres: toujours J+1 (date calendaire serveur → due_on Asana).
        $dueAt = (new \DateTimeImmutable('today'))->modify('+1 day');
        $dueOn = $dueAt->format('Y-m-d');
        $dueLabelFr = $dueAt->format('d/m/Y');

        $links = array_filter([
            $content->getVideoEditUrl() ? 'Montage (KDrive) : '.$content->getVideoEditUrl() : null,
            $content->getVideoFinalUrl() ? 'Final (KDrive) : '.$content->getVideoFinalUrl() : null,
            $content->getVideoSubmagicUrl() ? 'SubMagic : '.$content->getVideoSubmagicUrl() : null,
        ]);

        $notes = implode("\n", array_filter([
            'Relecture des sous-titres (Gestion des contenus).',
            'Échéance impérative : J+1 — le '.$dueLabelFr.' (due_on Asana : '.$dueOn.').',
            'Client : '.$clientName,
            '',
            $links !== [] ? "Liens :\n- ".implode("\n- ", $links) : null,
            $links !== [] ? '' : null,
            'Outil (fiche vidéo) : '.$videoUrl,
        ]));

        $taskData = [
            'name' => $name,
            'notes' => $notes,
            'workspace' => $workspaceGid,
            'projects' => [$projectGid],
            'due_on' => $dueOn,
        ];
        if ($assigneeGid !== null && trim($assigneeGid) !== '') {
            $taskData['assignee'] = trim($assigneeGid);
        }
        $payload = ['data' => $taskData];

        try {
            $resp = $this->httpClient->request('POST', 'https://app.asana.com/api/1.0/tasks', [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            $data = $resp->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $gid = $data['data']['gid'] ?? null;
        if (!is_string($gid) || trim($gid) === '') {
            return null;
        }

        return trim($gid);
    }

    /**
     * Met à jour l'échéance (due_on) d'une tâche Asana.
     */
    public function updateTaskDueOn(string $taskGid, \DateTimeInterface $dueOn): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $taskGid = trim($taskGid);
        if ($taskGid === '') {
            return false;
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request('PUT', 'https://app.asana.com/api/1.0/tasks/'.$taskGid, [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'data' => [
                        'due_on' => $dueOn->format('Y-m-d'),
                    ],
                ],
            ]);

            $status = $resp->getStatusCode();

            return $status >= 200 && $status < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Réassigne une tâche Asana à un utilisateur (gid Asana).
     */
    public function updateTaskAssignee(string $taskGid, ?string $assigneeGid): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $taskGid = trim($taskGid);
        if ($taskGid === '') {
            return false;
        }

        $assigneeGid = $assigneeGid !== null ? trim($assigneeGid) : '';
        if ($assigneeGid === '') {
            return false;
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request('PUT', 'https://app.asana.com/api/1.0/tasks/'.$taskGid, [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'data' => [
                        'assignee' => $assigneeGid,
                    ],
                ],
            ]);

            $status = $resp->getStatusCode();

            return $status >= 200 && $status < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Supprime une tâche Asana.
     */
    public function deleteTask(string $taskGid): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $taskGid = trim($taskGid);
        if ($taskGid === '') {
            return false;
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request('DELETE', 'https://app.asana.com/api/1.0/tasks/'.rawurlencode($taskGid), [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                ],
            ]);
            $status = $resp->getStatusCode();

            return $status >= 200 && $status < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ajoute une tâche existante à un projet Asana.
     */
    public function addTaskToProject(string $taskGid, string $projectGid): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $taskGid = trim($taskGid);
        $projectGid = trim($projectGid);
        if ($taskGid === '' || $projectGid === '') {
            return false;
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request(
                'POST',
                'https://app.asana.com/api/1.0/tasks/'.rawurlencode($taskGid).'/addProject',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => ['data' => ['project' => $projectGid]],
                ],
            );

            return $resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Retire une tâche d'un projet Asana (sans supprimer la tâche).
     */
    public function removeTaskFromProject(string $taskGid, string $projectGid): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $taskGid = trim($taskGid);
        $projectGid = trim($projectGid);
        if ($taskGid === '' || $projectGid === '') {
            return false;
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request(
                'POST',
                'https://app.asana.com/api/1.0/tasks/'.rawurlencode($taskGid).'/removeProject',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => ['data' => ['project' => $projectGid]],
                ],
            );

            return $resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Marque une tâche Asana comme terminée.
     */
    public function completeTask(string $taskGid): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $taskGid = trim($taskGid);
        if ($taskGid === '') {
            return false;
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request('PUT', 'https://app.asana.com/api/1.0/tasks/'.$taskGid, [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'data' => [
                        'completed' => true,
                    ],
                ],
            ]);

            $status = $resp->getStatusCode();

            return $status >= 200 && $status < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Toutes les tâches d'un projet (y compris terminées).
     *
     * @param list<string> $optFields
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateAllProjectTasks(string $projectGid, array $optFields = ['gid', 'name', 'completed']): \Generator
    {
        yield from $this->iterateProjectTasks($projectGid, $optFields, '1970-01-01T00:00:00Z');
    }

    /**
     * @param list<string> $optFields
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function iterateProjectTasks(string $projectGid, array $optFields, ?string $completedSince = null): \Generator
    {
        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));
        $offset = null;

        do {
            $url = 'https://app.asana.com/api/1.0/projects/'.rawurlencode($projectGid).'/tasks'
                .'?opt_fields='.implode(',', $optFields).'&limit=100';
            if ($completedSince !== null) {
                $url .= '&completed_since='.rawurlencode($completedSince);
            }
            if ($offset !== null) {
                $url .= '&offset='.rawurlencode($offset);
            }

            try {
                $resp = $this->httpClient->request('GET', $url, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                    ],
                ]);
                $payload = $resp->toArray(false);
            } catch (\Throwable) {
                return;
            }

            foreach ($payload['data'] ?? [] as $task) {
                if (is_array($task)) {
                    yield $task;
                }
            }

            $offset = isset($payload['next_page']['offset']) && is_string($payload['next_page']['offset'])
                ? $payload['next_page']['offset']
                : null;
        } while ($offset !== null);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function scoreMontageTaskMatch(array $task, Content $content, string $videoUrl): int
    {
        $rawTaskName = mb_strtolower(trim((string) ($task['name'] ?? '')));
        if (str_contains($rawTaskName, 'suivi') && (str_contains($rawTaskName, 'dérush') || str_contains($rawTaskName, 'derush'))) {
            return -1;
        }

        $notes = (string) ($task['notes'] ?? '');
        $contentId = $content->getId();
        if ($contentId !== null && str_contains($notes, '/videos/fiche/'.$contentId)) {
            if (!str_contains($notes, 'Vidéo créée depuis Gestion des contenus.')) {
                return -1;
            }

            $score = 100;
            if (!empty($task['completed'])) {
                $score -= 20;
            }

            return $score;
        }
        if ($videoUrl !== '' && str_contains($notes, $videoUrl)) {
            if (!str_contains($notes, 'Vidéo créée depuis Gestion des contenus.')) {
                return -1;
            }

            $score = 95;
            if (!empty($task['completed'])) {
                $score -= 20;
            }

            return $score;
        }

        $title = $this->normalizeTitleForMatch((string) ($content->getTitle() ?? ''));
        if ($title === '' || mb_strlen($title) < 12) {
            return -1;
        }

        $isMontageName = str_contains($rawTaskName, 'montage')
            || str_contains($rawTaskName, 'monter vid');
        if (!$isMontageName) {
            return -1;
        }

        $taskName = $this->normalizeTitleForMatch((string) ($task['name'] ?? ''));
        if ($taskName === '') {
            return -1;
        }

        $score = 0;
        if (str_contains($taskName, $title)) {
            $score = 80;
        } else {
            $prefix = mb_substr($title, 0, 30);
            if (mb_strlen($prefix) >= 12 && str_contains($taskName, $prefix)) {
                $score = 60;
            }
        }

        if ($score <= 0) {
            return -1;
        }

        if (!empty($task['completed'])) {
            $score -= 20;
        }

        if (str_contains($notes, 'Vidéo créée depuis Gestion des contenus.')) {
            $score -= 30;
        }

        return $score;
    }

    private function normalizeTitleForMatch(string $title): string
    {
        $normalized = mb_strtolower(trim($title));
        $normalized = (string) preg_replace('/^monter\s+vid[eé]o\s*:\s*/u', '', $normalized);
        $normalized = (string) preg_replace('/\s*-\s*montage\s+vid[eé]o\s*$/u', '', $normalized);
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * Crée une tâche Asana pour une demande de tournage.
     */
    public function createShootingRequestTask(ShootingRequest $request, string $requestUrl, ?string $fallbackAssigneeGid): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $stored = $request->getAsanaTaskGid();
        if ($stored !== null && $this->isTaskAccessible($stored)) {
            return $stored;
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));
        $workspaceGid = trim((string) (getenv('ASANA_WORKSPACE_GID') ?: ''));
        $client = $request->getClient();
        $projectGid = trim((string) ($client?->getAsanaProjectGid() ?? ''));

        if ($workspaceGid === '' || $projectGid === '') {
            return null;
        }

        $assignee = $request->getAssignedTo();
        $assigneeGid = $assignee?->getAsanaUserGid();
        if (($assigneeGid === null || trim($assigneeGid) === '') && $fallbackAssigneeGid !== null && trim($fallbackAssigneeGid) !== '') {
            $assigneeGid = trim($fallbackAssigneeGid);
        }

        $clientName = $client?->getName() ?? 'Sans client';
        $shootingDate = $request->getShootingDate();
        $dueOn = $shootingDate instanceof \DateTimeInterface ? $shootingDate->format('Y-m-d') : null;
        $dateLabel = $shootingDate instanceof \DateTimeInterface ? $shootingDate->format('d/m/Y') : '—';

        $name = sprintf('Tournage — %s — %s', $clientName, $dateLabel);

        $videoLines = [];
        foreach ($request->getVideos() as $video) {
            if (!$video instanceof Content) {
                continue;
            }
            $title = trim((string) ($video->getTitle() ?? ''));
            if ($title === '') {
                $title = 'Vidéo #'.($video->getId() ?? '?');
            }
            $pub = $video->getScheduledDate() instanceof \DateTimeInterface
                ? $video->getScheduledDate()->format('d/m/Y')
                : '—';
            $notes = trim((string) ($video->getNotes() ?? ''));
            $line = sprintf('- %s (publication prévue : %s)', $title, $pub);
            if ($notes !== '') {
                $line .= "\n  Note interne : ".$notes;
            }
            $videoLines[] = $line;
        }

        $description = trim((string) ($request->getDescription() ?? ''));
        $location = trim((string) ($request->getLocation() ?? ''));
        $videographerPlain = $this->richTextSanitizer->toPlainText($request->getVideographerNotes());

        $notes = implode("\n", array_filter([
            'Demande de tournage créée depuis Gestion des contenus.',
            '',
            'Client : '.$clientName,
            'Date du tournage : '.$dateLabel,
            $location !== '' ? 'Lieu : '.$location : null,
            '',
            $description !== '' ? "Description / consignes :\n".$description : null,
            $description !== '' ? '' : null,
            $videographerPlain !== '' ? "Infos vidéaste :\n".$videographerPlain : null,
            $videographerPlain !== '' ? '' : null,
            $videoLines !== [] ? "Vidéos à tourner :\n".implode("\n", $videoLines) : 'Vidéos à tourner : —',
            '',
            'Fiche demande : '.$requestUrl,
        ]));

        $taskData = [
            'name' => $name,
            'notes' => $notes,
            'workspace' => $workspaceGid,
            'projects' => [$projectGid],
        ];
        if ($dueOn !== null) {
            $taskData['due_on'] = $dueOn;
        }
        if ($assigneeGid !== null && trim($assigneeGid) !== '') {
            $taskData['assignee'] = trim($assigneeGid);
        }

        try {
            $resp = $this->httpClient->request('POST', 'https://app.asana.com/api/1.0/tasks', [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
                'json' => ['data' => $taskData],
            ]);
            $data = $resp->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $gid = $data['data']['gid'] ?? null;

        return is_string($gid) && trim($gid) !== '' ? trim($gid) : null;
    }

    /**
     * Crée une tâche Asana de suivi / contrôle dérush pour la CM (une seule tâche par session).
     *
     * @param list<Content>          $videos
     * @param array<int, string>     $videoUrls  id contenu => URL fiche vidéo
     */
    public function createDerushFollowUpTaskForCommunityManager(
        \App\Entity\Client $client,
        array $videos,
        ?string $globalRushesUrl,
        array $videoUrls,
        ?string $assigneeGid,
        ?string $fallbackAssigneeGid,
    ): ?string {
        if (!$this->isEnabled() || $videos === []) {
            return null;
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));
        $workspaceGid = trim((string) (getenv('ASANA_WORKSPACE_GID') ?: ''));
        $projectGid = trim((string) ($client->getAsanaProjectGid() ?? ''));

        if ($workspaceGid === '' || $projectGid === '') {
            return null;
        }

        $assigneeGid = $assigneeGid !== null && trim($assigneeGid) !== ''
            ? trim($assigneeGid)
            : ($fallbackAssigneeGid !== null && trim($fallbackAssigneeGid) !== '' ? trim($fallbackAssigneeGid) : null);

        $clientName = $client->getName() ?? 'Sans client';
        $today = new \DateTimeImmutable('today');
        $dueOn = $today->modify('+1 day')->format('Y-m-d');
        $dueLabelFr = $today->modify('+1 day')->format('d/m/Y');
        $count = count($videos);

        $name = sprintf(
            'Suivi dérush — %s — %d vidéo%s — %s',
            $clientName,
            $count,
            $count > 1 ? 's' : '',
            $today->format('d/m/Y'),
        );

        $videoLines = [];
        foreach ($videos as $video) {
            if (!$video instanceof Content) {
                continue;
            }
            $title = trim((string) ($video->getTitle() ?? ''));
            if ($title === '') {
                $title = 'Vidéo #'.($video->getId() ?? '?');
            }
            $pub = $video->getScheduledDate() instanceof \DateTimeInterface
                ? $video->getScheduledDate()->format('d/m/Y')
                : '—';
            $montage = $video->getAsanaMontageDueOn() instanceof \DateTimeInterface
                ? $video->getAsanaMontageDueOn()->format('d/m/Y')
                : '—';
            $editor = $video->getVideoEditor()?->getName() ?? '—';
            $subtitles = ($video->getVideoHasSubtitles() ?? false) ? 'Oui' : 'Non';
            $ficheUrl = $videoUrls[$video->getId() ?? 0] ?? null;
            $rushesUrl = trim((string) ($video->getVideoRushesUrl() ?? ''));

            $block = [
                '• '.$title,
                '  Publication : '.$pub,
                '  Montage souhaité : '.$montage,
                '  Monteur : '.$editor,
                '  Sous-titres : '.$subtitles,
            ];
            if ($rushesUrl !== '') {
                $block[] = '  Rushs : '.$rushesUrl;
            }
            if ($ficheUrl !== null && $ficheUrl !== '') {
                $block[] = '  Fiche : '.$ficheUrl;
            }
            if ($video->getAsanaTaskGid()) {
                $block[] = '  Tâche montage Asana : https://app.asana.com/0/0/'.$video->getAsanaTaskGid();
            }

            $videoLines[] = implode("\n", $block);
        }

        $cmName = $client->getCommunityManager()?->getName() ?? '—';

        $notes = implode("\n", array_filter([
            'Contrôle / suivi du dérush (Gestion des contenus).',
            'Vérifier que les rushs, titres, dates et consignes sont corrects avant le montage.',
            '',
            'Client : '.$clientName,
            'Community manager : '.$cmName,
            'Date du dérush : '.$today->format('d/m/Y'),
            'Échéance suivi : '.$dueLabelFr.' (J+1)',
            $globalRushesUrl !== null && trim($globalRushesUrl) !== '' ? 'Lien rushs commun : '.trim($globalRushesUrl) : null,
            '',
            sprintf('Vidéos passées en montage (%d) :', $count),
            $videoLines !== [] ? implode("\n\n", $videoLines) : '—',
        ]));

        $taskData = [
            'name' => $name,
            'notes' => $notes,
            'workspace' => $workspaceGid,
            'projects' => [$projectGid],
            'due_on' => $dueOn,
        ];
        if ($assigneeGid !== null) {
            $taskData['assignee'] = $assigneeGid;
        }

        try {
            $resp = $this->httpClient->request('POST', 'https://app.asana.com/api/1.0/tasks', [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
                'json' => ['data' => $taskData],
            ]);
            $data = $resp->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $gid = $data['data']['gid'] ?? null;

        return is_string($gid) && trim($gid) !== '' ? trim($gid) : null;
    }
}

