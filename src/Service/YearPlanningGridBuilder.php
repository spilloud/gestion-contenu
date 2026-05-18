<?php

namespace App\Service;

use App\Entity\CalendarEvent;
use App\Entity\Content;

final class YearPlanningGridBuilder
{
    private const MAX_WEEK_ROWS = 6;

    /** @var list<array{label: string, color: string}> */
    private const FORMAT_LEGEND = [
        ['label' => 'Vidéo', 'color' => '#6d28d9'],
        ['label' => 'Carrousel', 'color' => '#2563eb'],
        ['label' => 'Story', 'color' => '#db2777'],
        ['label' => 'Reel', 'color' => '#ea580c'],
        ['label' => 'Photo', 'color' => '#0891b2'],
        ['label' => 'Article / post', 'color' => '#0d9488'],
        ['label' => 'Autre', 'color' => '#64748b'],
    ];

    /** @var array<int, string> */
    private const MONTH_LABELS = [
        1 => 'Janv.',
        2 => 'Févr.',
        3 => 'Mars',
        4 => 'Avr.',
        5 => 'Mai',
        6 => 'Juin',
        7 => 'Juil.',
        8 => 'Août',
        9 => 'Sept.',
        10 => 'Oct.',
        11 => 'Nov.',
        12 => 'Déc.',
    ];

    /**
     * @param Content[]       $contents
     * @param CalendarEvent[] $events
     *
     * @return array{
     *     year: int,
     *     months: array<int, string>,
     *     rowCount: int,
     *     grid: array<int, list<array<string, mixed>|null>>
     * }
     */
    /**
     * @return list<array{label: string, color: string}>
     */
    public static function getFormatLegend(): array
    {
        return self::FORMAT_LEGEND;
    }

    public function build(int $year, array $contents, array $events): array
    {
        $yearStart = new \DateTimeImmutable(sprintf('%d-01-01', $year));
        $yearEnd = new \DateTimeImmutable(sprintf('%d-12-31', $year));
        $monthBounds = $this->buildMonthBounds($year);
        $yearWeeks = $this->buildYearWeeks($yearStart, $yearEnd);

        /** @var array<int, list<array<string, mixed>|null>> $grid */
        $grid = [];
        $maxRows = 0;

        for ($month = 1; $month <= 12; ++$month) {
            [$monthStart, $monthEnd] = $monthBounds[$month];
            $monthWeeks = [];

            foreach ($yearWeeks as $week) {
                if ($week['end'] < $monthStart || $week['start'] > $monthEnd) {
                    continue;
                }

                $cellStart = max($week['start'], $monthStart);
                $cellEnd = min($week['end'], $monthEnd);

                $monthWeeks[] = [
                    'isoWeek' => $week['isoWeek'],
                    'start' => $cellStart,
                    'end' => $cellEnd,
                    'contents' => [],
                    'events' => [],
                ];
            }

            $maxRows = max($maxRows, \count($monthWeeks));

            while (\count($monthWeeks) < self::MAX_WEEK_ROWS) {
                $monthWeeks[] = null;
            }

            $grid[$month] = $monthWeeks;
        }

        $rowCount = max($maxRows, 1);

        foreach ($contents as $content) {
            $scheduled = $content->getScheduledDate();
            if ($scheduled === null) {
                continue;
            }

            $date = $this->toDateImmutable($scheduled);
            if ($date < $yearStart || $date > $yearEnd) {
                continue;
            }

            $formatName = $content->getFormat()?->getName() ?? '';
            $formatKey = mb_strtolower($formatName);

            $this->placeContent($grid, $monthBounds, $date, [
                'id' => $content->getId(),
                'title' => $content->getTitle() ?? '',
                'sortDate' => $date->format('Y-m-d'),
                'formatName' => $formatName,
                'formatColor' => $this->resolveFormatColor($formatKey, $content),
                'isVideo' => str_contains($formatKey, 'vidéo') || str_contains($formatKey, 'video'),
            ]);
        }

        foreach ($events as $event) {
            $start = $this->toDateImmutable($event->getStartDate());
            $end = $this->toDateImmutable($event->getEndDate());

            if ($end < $yearStart || $start > $yearEnd) {
                continue;
            }

            $this->placeEvent($grid, $monthBounds, max($start, $yearStart), min($end, $yearEnd), [
                'id' => $event->getId(),
                'title' => $event->getTitle() ?? '',
                'color' => $event->getColor(),
                'textColor' => $event->getTextColor(),
            ]);
        }

        $this->sortCells($grid);

        return [
            'year' => $year,
            'months' => self::MONTH_LABELS,
            'rowCount' => $rowCount,
            'grid' => $grid,
        ];
    }

    /**
     * @return array<int, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function buildMonthBounds(int $year): array
    {
        $bounds = [];
        for ($month = 1; $month <= 12; ++$month) {
            $start = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
            $bounds[$month] = [$start, $start->modify('last day of this month')];
        }

        return $bounds;
    }

    /**
     * @return list<array{isoWeek: int, start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    private function buildYearWeeks(\DateTimeImmutable $yearStart, \DateTimeImmutable $yearEnd): array
    {
        $cursor = $yearStart->modify('monday this week');
        if ($cursor > $yearStart) {
            $cursor = $cursor->modify('-7 days');
        }

        $weeks = [];
        while ($cursor <= $yearEnd) {
            $weekEnd = $cursor->modify('+6 days');
            if ($weekEnd >= $yearStart) {
                $weeks[] = [
                    'isoWeek' => (int) $cursor->format('W'),
                    'start' => $cursor,
                    'end' => $weekEnd,
                ];
            }
            $cursor = $cursor->modify('+7 days');
        }

        return $weeks;
    }

    /**
     * @param array<int, list<array<string, mixed>|null>> $grid
     * @param array<int, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $monthBounds
     * @param array{id: int|null, title: string, sortDate: string} $item
     */
    private function placeContent(array &$grid, array $monthBounds, \DateTimeImmutable $date, array $item): void
    {
        for ($month = 1; $month <= 12; ++$month) {
            [$monthStart, $monthEnd] = $monthBounds[$month];
            if ($date < $monthStart || $date > $monthEnd) {
                continue;
            }

            foreach ($grid[$month] as &$cell) {
                if ($cell === null) {
                    continue;
                }
                if ($date >= $cell['start'] && $date <= $cell['end']) {
                    $cell['contents'][] = $item;
                    break;
                }
            }
            unset($cell);
        }
    }

    /**
     * @param array<int, list<array<string, mixed>|null>> $grid
     * @param array<int, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $monthBounds
     * @param array{id: int|null, title: string, color: string, textColor: string} $item
     */
    private function placeEvent(
        array &$grid,
        array $monthBounds,
        \DateTimeImmutable $eventStart,
        \DateTimeImmutable $eventEnd,
        array $item,
    ): void {
        for ($month = 1; $month <= 12; ++$month) {
            [$monthStart, $monthEnd] = $monthBounds[$month];
            if ($eventEnd < $monthStart || $eventStart > $monthEnd) {
                continue;
            }

            foreach ($grid[$month] as &$cell) {
                if ($cell === null) {
                    continue;
                }

                $overlapStart = max($eventStart, $cell['start']);
                $overlapEnd = min($eventEnd, $cell['end']);
                if ($overlapStart > $overlapEnd) {
                    continue;
                }

                $daySpan = (int) $overlapStart->diff($overlapEnd)->days + 1;
                $found = false;
                foreach ($cell['events'] as &$existing) {
                    if ($existing['id'] === $item['id']) {
                        $existing['daySpan'] = max($existing['daySpan'], $daySpan);
                        $existing['vertical'] = $existing['daySpan'] > 1;
                        $found = true;
                        break;
                    }
                }
                unset($existing);

                if (!$found) {
                    $cell['events'][] = $item + [
                        'vertical' => $daySpan > 1,
                        'daySpan' => $daySpan,
                        'sortDate' => $overlapStart->format('Y-m-d'),
                    ];
                }
            }
            unset($cell);
        }
    }

    /**
     * @param array<int, list<array<string, mixed>|null>> $grid
     */
    private function sortCells(array &$grid): void
    {
        foreach ($grid as &$monthCells) {
            foreach ($monthCells as &$cell) {
                if ($cell === null) {
                    continue;
                }

                usort(
                    $cell['contents'],
                    static fn (array $a, array $b): int => [$a['sortDate'], $a['title']]
                        <=> [$b['sortDate'], $b['title']]
                );

                usort(
                    $cell['events'],
                    static fn (array $a, array $b): int => [$a['sortDate'], $a['title']]
                        <=> [$b['sortDate'], $b['title']]
                );
            }
            unset($cell);
        }
        unset($monthCells);
    }

    private function toDateImmutable(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
    }

    private function resolveFormatColor(string $formatKey, Content $content): string
    {
        if (str_contains($formatKey, 'vidéo') || str_contains($formatKey, 'video')) {
            return '#6d28d9';
        }
        if (str_contains($formatKey, 'carrousel') || str_contains($formatKey, 'carousel')) {
            return '#2563eb';
        }
        if (str_contains($formatKey, 'story') || str_contains($formatKey, 'stories')) {
            return '#db2777';
        }
        if (str_contains($formatKey, 'reel')) {
            return '#ea580c';
        }
        if (str_contains($formatKey, 'photo') || str_contains($formatKey, 'image')) {
            return '#0891b2';
        }
        if (str_contains($formatKey, 'article') || str_contains($formatKey, 'post') || str_contains($formatKey, 'linkedin')) {
            return '#0d9488';
        }

        $status = $content->getStatus();
        if ($status !== null) {
            $statusColor = $status->getColor();
            if (is_string($statusColor) && str_starts_with($statusColor, '#')) {
                return $statusColor;
            }
        }

        return '#64748b';
    }
}
