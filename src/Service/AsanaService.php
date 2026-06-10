<?php

namespace App\Service;

use App\Entity\Content;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AsanaService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
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

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));

        try {
            $resp = $this->httpClient->request(
                'GET',
                'https://app.asana.com/api/1.0/tasks/'.rawurlencode($taskGid).'?opt_fields=name,notes,assignee.name,completed,due_on,permalink_url',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                    ],
                ],
            );
            if ($resp->getStatusCode() === 404) {
                return null;
            }
            $data = $resp->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $task = $data['data'] ?? null;

        return is_array($task) ? $task : null;
    }

    public function isTaskAccessible(string $taskGid): bool
    {
        return $this->fetchTask($taskGid) !== null;
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
     * Crée une tâche Asana pour une vidéo, si configuré.
     * Retourne le task gid (string) ou null si non créé.
     */
    public function createTaskForVideo(Content $content, string $videoUrl, ?string $fallbackAssigneeGid): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $stored = $content->getAsanaTaskGid();
        if ($stored !== null && $this->isTaskAccessible($stored)) {
            return $stored;
        }

        $existing = $this->findMontageTaskForVideo($content, $videoUrl);
        if ($existing !== null) {
            return $existing;
        }

        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));
        $workspaceGid = trim((string) (getenv('ASANA_WORKSPACE_GID') ?: ''));
        $client = $content->getClient();
        $projectGid = $client ? (string) ($client->getAsanaProjectGid() ?? '') : '';
        $projectGid = trim($projectGid);

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

        // Délai montage: J+2 (indépendant de la date planifiée du calendrier).
        $dueAt = (new \DateTimeImmutable('today'))->modify('+2 days');
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
            'Échéance (J+2) : le '.$dueLabelFr.' — due_on Asana '.$dueOn.'.',
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
     * @param list<string> $optFields
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function iterateProjectTasks(string $projectGid, array $optFields): \Generator
    {
        $token = trim((string) getenv('ASANA_ACCESS_TOKEN'));
        $offset = null;

        do {
            $url = 'https://app.asana.com/api/1.0/projects/'.rawurlencode($projectGid).'/tasks'
                .'?opt_fields='.implode(',', $optFields).'&limit=100';
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
        $notes = (string) ($task['notes'] ?? '');
        $contentId = $content->getId();
        if ($contentId !== null && str_contains($notes, '/videos/fiche/'.$contentId)) {
            return 100;
        }
        if ($videoUrl !== '' && str_contains($notes, $videoUrl)) {
            return 95;
        }

        $title = $this->normalizeTitleForMatch((string) ($content->getTitle() ?? ''));
        if ($title === '' || mb_strlen($title) < 12) {
            return -1;
        }

        $rawTaskName = mb_strtolower(trim((string) ($task['name'] ?? '')));
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
}

