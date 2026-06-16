<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\User;
use App\Repository\ContentRepository;
use App\Repository\ShootingRequestRepository;
use App\Repository\UserRepository;

/**
 * Vue planning / charge monteur (lecture seule, sans nouveau workflow).
 */
final class EditorWorkloadPlanningBuilder
{
    public const MONTAGE_LEAD_DAYS = 3;
    public const RUSH_AFTER_SHOOTING_DAYS = 1;

    private const ANTICIPE_STATUSES = ['Tournage à prévoir'];

    private const RUSH_STATUSES = [
        'Brouillon (Dérush)',
        'Rushs / à dispatcher',
    ];

    private const FILE_MONTAGE_STATUSES = [
        'Montage à faire',
        'Retouches (Monteur)',
        'Montage en cours',
    ];

    private const LIVRE_MONTAGE_STATUSES = [
        'À valider (Prod)',
        'Sous-titrage (SubMagic)',
        'Prépa CM (sans sous-titres)',
        'Sous-titres à valider',
        'À valider (CM)',
        'À valider (Client)',
        'À faire valider au client',
        'Prête à programmer',
        'Programmée',
    ];

    private const PUBLISHED_STATUSES = ['Publiée'];

    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly ShootingRequestRepository $shootingRequestRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $today = new \DateTimeImmutable('today');

        /** @var array<int, array{shootingDate: \DateTimeInterface, requestId: int}> $shootingByVideoId */
        $shootingByVideoId = $this->buildShootingDatesByVideoId();

        $editors = $this->userRepository->findEditorsOrdered();
        $lanes = [];
        foreach ($editors as $editor) {
            $lanes[$editor->getId() ?? 0] = $this->emptyLane($editor);
        }
        $unassigned = $this->emptyLane(null);

        /** @var Content[] $videos */
        $videos = $this->contentRepository->findVideosForEditorPlanning();

        foreach ($videos as $video) {
            $statusName = $video->getStatus()?->getName() ?? '';
            if (in_array($statusName, self::PUBLISHED_STATUSES, true)) {
                continue;
            }

            $editor = $this->resolveEditor($video);
            $videoId = $video->getId();
            $shootingMeta = $videoId !== null ? ($shootingByVideoId[$videoId] ?? null) : null;
            $shootingDate = $shootingMeta['shootingDate'] ?? null;

            $item = $this->buildItem($video, $statusName, $shootingDate, $today);
            $column = $this->resolveColumn($statusName, $shootingDate, $today);

            if ($editor === null) {
                $unassigned['columns'][$column][] = $item;
                if ($item['alertLevel'] !== null) {
                    $unassigned['alerts'][] = $item;
                }
                continue;
            }

            $editorId = $editor->getId();
            if ($editorId === null || !isset($lanes[$editorId])) {
                $lanes[$editorId] = $this->emptyLane($editor);
            }

            $lanes[$editorId]['columns'][$column][] = $item;
            if ($item['alertLevel'] !== null) {
                $lanes[$editorId]['alerts'][] = $item;
            }
        }

        $upcomingShootings = $this->buildUpcomingShootingsByEditorId($today);

        $editorLanes = [];
        foreach ($editors as $editor) {
            $id = $editor->getId();
            if ($id === null) {
                continue;
            }
            $lane = $lanes[$id] ?? $this->emptyLane($editor);
            $lane['upcomingShootings'] = $upcomingShootings[$id] ?? [];
            $lane['summary'] = $this->summarizeLane($lane);
            $editorLanes[] = $this->sortLane($lane);
        }

        $unassigned['upcomingShootings'] = [];
        $unassigned['summary'] = $this->summarizeLane($unassigned);
        $unassigned = $this->sortLane($unassigned);

        return [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'rules' => [
                'montageLeadDays' => self::MONTAGE_LEAD_DAYS,
                'rushAfterShootingDays' => self::RUSH_AFTER_SHOOTING_DAYS,
                'description' => 'Échéance montage = date publication − '
                    .self::MONTAGE_LEAD_DAYS
                    .' j. Rush attendu = date tournage + '
                    .self::RUSH_AFTER_SHOOTING_DAYS
                    .' j (si tournage planifié). Monteur = fiche vidéo ou client.',
            ],
            'columnLabels' => [
                'anticipe' => 'À venir',
                'rush' => 'Rush / dérush',
                'file' => 'File montage',
                'livre' => 'Montage livré',
            ],
            'editors' => $editorLanes,
            'unassigned' => $unassigned,
            'totals' => [
                'videosInPipeline' => count($videos),
                'alertCount' => array_sum(array_map(
                    static fn (array $lane): int => count($lane['alerts']),
                    array_merge($editorLanes, [$unassigned]),
                )),
            ],
        ];
    }

    /**
     * @return array<int, array{shootingDate: \DateTimeInterface, requestId: int}>
     */
    private function buildShootingDatesByVideoId(): array
    {
        $map = [];
        foreach ($this->shootingRequestRepository->findAllForList() as $request) {
            $shootingDate = $request->getShootingDate();
            if ($shootingDate === null) {
                continue;
            }
            $requestId = $request->getId() ?? 0;
            foreach ($request->getVideos() as $video) {
                $videoId = $video->getId();
                if ($videoId === null) {
                    continue;
                }
                if (!isset($map[$videoId]) || $shootingDate < $map[$videoId]['shootingDate']) {
                    $map[$videoId] = [
                        'shootingDate' => $shootingDate,
                        'requestId' => $requestId,
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private function buildUpcomingShootingsByEditorId(\DateTimeImmutable $today): array
    {
        $byEditor = [];
        foreach ($this->shootingRequestRepository->findAllForList() as $request) {
            $shootingDate = $request->getShootingDate();
            if ($shootingDate === null) {
                continue;
            }
            $shootingImmutable = $shootingDate instanceof \DateTimeImmutable
                ? $shootingDate
                : \DateTimeImmutable::createFromInterface($shootingDate);
            if ($shootingImmutable < $today->modify('-7 days')) {
                continue;
            }

            $editor = $request->getClient()?->getEditor();
            $editorId = $editor?->getId();
            if ($editorId === null) {
                continue;
            }

            $videoTitles = [];
            foreach ($request->getVideos() as $video) {
                $videoTitles[] = $video->getTitle();
            }

            $byEditor[$editorId][] = [
                'requestId' => $request->getId(),
                'client' => $request->getClient()?->getName(),
                'shootingDate' => $shootingImmutable->format('Y-m-d'),
                'expectedRushDate' => $shootingImmutable->modify('+'.self::RUSH_AFTER_SHOOTING_DAYS.' day')->format('Y-m-d'),
                'location' => $request->getLocation(),
                'videoCount' => count($videoTitles),
                'videoTitles' => $videoTitles,
            ];
        }

        foreach ($byEditor as &$rows) {
            usort($rows, static fn (array $a, array $b): int => strcmp($a['shootingDate'], $b['shootingDate']));
        }

        return $byEditor;
    }

    private function resolveEditor(Content $video): ?User
    {
        $assigned = $video->getVideoEditor();
        if ($assigned !== null) {
            return $assigned;
        }

        return $video->getClient()?->getEditor();
    }

    private function resolveColumn(string $statusName, ?\DateTimeInterface $shootingDate, \DateTimeImmutable $today): string
    {
        if (in_array($statusName, self::FILE_MONTAGE_STATUSES, true)) {
            return 'file';
        }
        if (in_array($statusName, self::LIVRE_MONTAGE_STATUSES, true)) {
            return 'livre';
        }
        if (in_array($statusName, self::RUSH_STATUSES, true)) {
            return 'rush';
        }
        if (in_array($statusName, self::ANTICIPE_STATUSES, true)) {
            if ($shootingDate !== null) {
                $rushExpected = ($shootingDate instanceof \DateTimeImmutable
                    ? $shootingDate
                    : \DateTimeImmutable::createFromInterface($shootingDate)
                )->modify('+'.self::RUSH_AFTER_SHOOTING_DAYS.' day');
                if ($rushExpected <= $today) {
                    return 'rush';
                }
            }

            return 'anticipe';
        }

        return 'anticipe';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItem(
        Content $video,
        string $statusName,
        ?\DateTimeInterface $shootingDate,
        \DateTimeImmutable $today,
    ): array {
        $publication = $video->getScheduledDate();
        $publicationImmutable = $publication !== null
            ? ($publication instanceof \DateTimeImmutable
                ? $publication
                : \DateTimeImmutable::createFromInterface($publication))
            : null;

        $montageDeadline = $publicationImmutable?->modify('-'.self::MONTAGE_LEAD_DAYS.' days');

        $expectedRush = null;
        if ($shootingDate !== null) {
            $expectedRush = ($shootingDate instanceof \DateTimeImmutable
                ? $shootingDate
                : \DateTimeImmutable::createFromInterface($shootingDate)
            )->modify('+'.self::RUSH_AFTER_SHOOTING_DAYS.' day');
        }

        [$alertLevel, $alertReason] = $this->resolveAlert(
            $statusName,
            $montageDeadline,
            $publicationImmutable,
            $today,
        );

        $status = $video->getStatus();

        return [
            'id' => $video->getId(),
            'title' => $video->getTitle(),
            'client' => $video->getClient()?->getName(),
            'status' => $statusName,
            'statusColor' => $status?->getColor(),
            'publicationDate' => $publicationImmutable?->format('Y-m-d'),
            'montageDeadline' => $montageDeadline?->format('Y-m-d'),
            'shootingDate' => $shootingDate !== null
                ? ($shootingDate instanceof \DateTimeImmutable
                    ? $shootingDate
                    : \DateTimeImmutable::createFromInterface($shootingDate)
                )->format('Y-m-d')
                : null,
            'expectedRushDate' => $expectedRush?->format('Y-m-d'),
            'hasAsanaMontage' => $video->getAsanaTaskGid() !== null,
            'editorSource' => $video->getVideoEditor() !== null ? 'fiche' : ($video->getClient()?->getEditor() !== null ? 'client' : null),
            'alertLevel' => $alertLevel,
            'alertReason' => $alertReason,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveAlert(
        string $statusName,
        ?\DateTimeImmutable $montageDeadline,
        ?\DateTimeImmutable $publicationDate,
        \DateTimeImmutable $today,
    ): array {
        if (in_array($statusName, self::LIVRE_MONTAGE_STATUSES, true)
            || in_array($statusName, self::PUBLISHED_STATUSES, true)) {
            return [null, null];
        }

        if ($montageDeadline !== null && $montageDeadline < $today) {
            return ['danger', 'Échéance montage dépassée'];
        }

        if ($publicationDate !== null
            && $publicationDate <= $today->modify('+7 days')
            && !in_array($statusName, self::LIVRE_MONTAGE_STATUSES, true)) {
            return ['warning', 'Publication dans 7 j ou moins — montage pas livré'];
        }

        if ($montageDeadline !== null && $montageDeadline <= $today->modify('+2 days')) {
            return ['warning', 'Échéance montage dans 3 j ou moins'];
        }

        return [null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyLane(?User $editor): array
    {
        return [
            'editor' => $editor !== null ? [
                'id' => $editor->getId(),
                'name' => $editor->getName(),
            ] : null,
            'columns' => [
                'anticipe' => [],
                'rush' => [],
                'file' => [],
                'livre' => [],
            ],
            'alerts' => [],
            'upcomingShootings' => [],
            'summary' => [],
        ];
    }

    /**
     * @param array<string, mixed> $lane
     *
     * @return array<string, int>
     */
    private function summarizeLane(array $lane): array
    {
        $columns = $lane['columns'];
        $active = count($columns['anticipe']) + count($columns['rush']) + count($columns['file']);

        return [
            'anticipe' => count($columns['anticipe']),
            'rush' => count($columns['rush']),
            'file' => count($columns['file']),
            'livre' => count($columns['livre']),
            'active' => $active,
            'alerts' => count($lane['alerts']),
            'upcomingShootings' => count($lane['upcomingShootings']),
        ];
    }

    /**
     * @param array<string, mixed> $lane
     *
     * @return array<string, mixed>
     */
    private function sortLane(array $lane): array
    {
        foreach (array_keys($lane['columns']) as $column) {
            usort($lane['columns'][$column], static function (array $a, array $b): int {
                $da = $a['montageDeadline'] ?? $a['publicationDate'] ?? '9999-99-99';
                $db = $b['montageDeadline'] ?? $b['publicationDate'] ?? '9999-99-99';
                $cmp = strcmp($da, $db);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp($a['title'] ?? '', $b['title'] ?? '');
            });
        }

        usort($lane['alerts'], static function (array $a, array $b): int {
            $order = ['danger' => 0, 'warning' => 1];
            $oa = $order[$a['alertLevel'] ?? ''] ?? 2;
            $ob = $order[$b['alertLevel'] ?? ''] ?? 2;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return strcmp($a['montageDeadline'] ?? '', $b['montageDeadline'] ?? '');
        });

        return $lane;
    }
}
