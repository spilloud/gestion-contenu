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
        $title = $content->getTitle() ?? '';
        $name = trim($clientName.' — '.$title);
        if ($name === '—') {
            $name = 'Vidéo';
        }

        $scheduled = $content->getScheduledDate();
        $dueOn = $scheduled instanceof \DateTimeInterface ? $scheduled->format('Y-m-d') : null;

        $notes = implode("\n", array_filter([
            'Vidéo créée depuis Gestion des contenus.',
            'Client : '.$clientName,
            $title !== '' ? 'Titre : '.$title : null,
            $dueOn ? 'Date prévue : '.$dueOn : null,
            'Sous-titres : '.(($content->getVideoHasSubtitles() ?? false) ? 'Oui' : 'Non'),
            '',
            'Fiche vidéo : '.$videoUrl,
        ]));

        $payload = [
            'data' => array_filter([
                'name' => $name,
                'notes' => $notes,
                'workspace' => $workspaceGid,
                'projects' => [$projectGid],
                'assignee' => $assigneeGid,
                'due_on' => $dueOn,
            ], static fn ($v) => $v !== null && $v !== ''),
        ];

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
}

