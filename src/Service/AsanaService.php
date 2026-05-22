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
     * Crée une tâche Asana pour une vidéo, si configuré.
     * Retourne le task gid (string) ou null si non créé.
     */
    public function createTaskForVideo(Content $content, string $videoUrl, ?string $fallbackAssigneeGid): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if ($content->getAsanaTaskGid()) {
            return $content->getAsanaTaskGid();
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
        $editor = $content->getVideoEditor();
        if ($editor && $editor->getAsanaUserGid()) {
            $assigneeGid = $editor->getAsanaUserGid();
        } elseif ($fallbackAssigneeGid !== null && trim($fallbackAssigneeGid) !== '') {
            $assigneeGid = trim($fallbackAssigneeGid);
        }

        $clientName = $client?->getName() ?? 'Sans client';
        $title = trim((string) ($content->getTitle() ?? ''));
        // Titre Asana: Projet (client) + nom de la vidéo.
        $name = trim($clientName.' — '.($title !== '' ? $title : 'Vidéo'));

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
            'name' => $name.' — échéance J+2 ('.$dueLabelFr.')',
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
        $title = trim((string) ($content->getTitle() ?? ''));
        $baseName = trim('Relecture sous-titres — '.($title !== '' ? $title : $clientName));

        // Délai relecture sous-titres: toujours J+1 (date calendaire serveur → due_on Asana).
        $dueAt = (new \DateTimeImmutable('today'))->modify('+1 day');
        $dueOn = $dueAt->format('Y-m-d');
        $dueLabelFr = $dueAt->format('d/m/Y');
        $name = $baseName.' — échéance J+1 ('.$dueLabelFr.')';

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
}

