<?php

namespace App\Service;

use App\Entity\CalendarEvent;
use App\Entity\Content;

final class YearPlanningGridBuilder
{
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
     *     weeks: list<array{
     *         key: string,
     *         isoWeek: int,
     *         label: string,
     *         start: \DateTimeImmutable,
     *         end: \DateTimeImmutable,
     *         cells: array<int, list<array<string, mixed>>>
     *     }>
     * }
     */
    public function build(int $year, array $contents, array $events): array
    {
        $yearStart = new \DateTimeImmutable(sprintf('%d-01-01', $year));
        $yearEnd = new \DateTimeImmutable(sprintf('%d-12-31', $year));
        $monthBounds = $this->buildMonthBounds($year);

        $weeks = $this->buildWeekRows($yearStart, $yearEnd);
        foreach ($weeks as &$week) {
            $week['cells'] = array_fill(1, 12, []);
        }
        unset($week);

        foreach ($contents as $content) {
            $scheduled = $content->getScheduledDate();
            if ($scheduled === null) {
                continue;
            }

            $date = $this->toDateImmutable($scheduled);
            if ($date < $yearStart || $date > $yearEnd) {
                continue;
            }

            $this->placeInGrid(
                $weeks,
                $monthBounds,
                $date,
                $date,
                [
                    'type' => 'content',
                    'id' => $content->getId(),
                    'title' => $content->getTitle() ?? '',
                    'sortDate' => $date->format('Y-m-d'),
                ]
            );
        }

        foreach ($events as $event) {
            $start = $this->toDateImmutable($event->getStartDate());
            $end = $this->toDateImmutable($event->getEndDate());

            if ($end < $yearStart || $start > $yearEnd) {
                continue;
            }

            if ($start < $yearStart) {
                $start = $yearStart;
            }
            if ($end > $yearEnd) {
                $end = $yearEnd;
            }

            $this->placeInGrid(
                $weeks,
                $monthBounds,
                $start,
                $end,
                [
                    'type' => 'event',
                    'id' => $event->getId(),
                    'title' => $event->getTitle() ?? '',
                    'color' => $event->getColor(),
                    'textColor' => $event->getTextColor(),
                    'sortDate' => $start->format('Y-m-d'),
                ]
            );
        }

        foreach ($weeks as &$week) {
            for ($month = 1; $month <= 12; ++$month) {
                $week['cells'][$month] = $this->sortAndDedupeCell($week['cells'][$month]);
            }
        }
        unset($week);

        return [
            'year' => $year,
            'months' => self::MONTH_LABELS,
            'weeks' => $weeks,
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
     * @return list<array{
     *     key: string,
     *     isoWeek: int,
     *     label: string,
     *     start: \DateTimeImmutable,
     *     end: \DateTimeImmutable,
     *     cells: array<int, list<array<string, mixed>>>
     * }>
     */
    private function buildWeekRows(\DateTimeImmutable $yearStart, \DateTimeImmutable $yearEnd): array
    {
        $cursor = $yearStart->modify('monday this week');
        if ($cursor > $yearStart) {
            $cursor = $cursor->modify('-7 days');
        }

        $weeks = [];
        while ($cursor <= $yearEnd) {
            $weekEnd = $cursor->modify('+6 days');
            if ($weekEnd >= $yearStart) {
                $displayStart = $cursor < $yearStart ? $yearStart : $cursor;
                $displayEnd = $weekEnd > $yearEnd ? $yearEnd : $weekEnd;
                $isoWeek = (int) $cursor->format('W');

                $weeks[] = [
                    'key' => $cursor->format('Y-m-d'),
                    'isoWeek' => $isoWeek,
                    'label' => sprintf(
                        'S%02d · %s – %s',
                        $isoWeek,
                        $this->formatShortDate($displayStart),
                        $this->formatShortDate($displayEnd)
                    ),
                    'start' => $cursor,
                    'end' => $weekEnd,
                    'cells' => [],
                ];
            }

            $cursor = $cursor->modify('+7 days');
        }

        return $weeks;
    }

    /**
     * @param list<array{cells: array<int, list<array<string, mixed>>>}> $weeks
     * @param array<int, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $monthBounds
     * @param array<string, mixed> $item
     */
    private function placeInGrid(
        array &$weeks,
        array $monthBounds,
        \DateTimeImmutable $itemStart,
        \DateTimeImmutable $itemEnd,
        array $item,
    ): void {
        foreach ($weeks as &$week) {
            if ($itemEnd < $week['start'] || $itemStart > $week['end']) {
                continue;
            }

            for ($month = 1; $month <= 12; ++$month) {
                [$monthStart, $monthEnd] = $monthBounds[$month];

                $overlapStart = max($itemStart, $week['start'], $monthStart);
                $overlapEnd = min($itemEnd, $week['end'], $monthEnd);

                if ($overlapStart <= $overlapEnd) {
                    $week['cells'][$month][] = $item;
                }
            }
        }
        unset($week);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function sortAndDedupeCell(array $items): array
    {
        usort(
            $items,
            static fn (array $a, array $b): int => [$a['sortDate'], $a['title'], $a['type'], $a['id']]
                <=> [$b['sortDate'], $b['title'], $b['type'], $b['id']]
        );

        $seen = [];
        $deduped = [];
        foreach ($items as $item) {
            $key = $item['type'].'-'.$item['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $item;
        }

        return $deduped;
    }

    private function toDateImmutable(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
    }

    private function formatShortDate(\DateTimeImmutable $date): string
    {
        static $formatter = null;
        if ($formatter === null) {
            $formatter = new \IntlDateFormatter(
                'fr_FR',
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                null,
                null,
                'd MMM'
            );
        }

        $formatted = $formatter->format($date);

        return is_string($formatted) ? $formatted : $date->format('d/m');
    }
}
