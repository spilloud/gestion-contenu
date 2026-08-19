<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\User;

/**
 * Formate les entrées du journal de parcours (acteur, délégation via fiche).
 */
final class WorkflowJournalFormatter
{
    public function formatActor(?User $user): string
    {
        if ($user === null) {
            return '—';
        }

        $name = trim((string) ($user->getName() ?? ''));
        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($user->getEmail() ?? ''));

        return $email !== '' ? $email : '—';
    }

    public function enrichTransitionDetail(
        Content $content,
        string $from,
        string $to,
        ?User $actor,
        ?string $actorLabelOverride = null,
    ): string {
        $lines = [sprintf('%s → %s', $from, $to)];
        if ($actorLabelOverride !== null && trim($actorLabelOverride) !== '') {
            $lines[] = 'Par : '.trim($actorLabelOverride);
        } else {
            $lines[] = 'Par : '.$this->formatActor($actor);
        }

        return implode("\n", $lines);
    }

    public function enrichDelegationDetail(
        string $label,
        ?User $previous,
        ?User $next,
        ?User $actor,
        ?string $actorLabelOverride = null,
    ): string {
        $old = $previous instanceof User ? $this->formatActor($previous) : '—';
        $new = $next instanceof User ? $this->formatActor($next) : '—';

        $lines = [
            sprintf('%s : %s → %s', $label, $old, $new),
        ];
        if ($actorLabelOverride !== null && trim($actorLabelOverride) !== '') {
            $lines[] = 'Par : '.trim($actorLabelOverride);
        } elseif ($actor !== null) {
            $lines[] = 'Par : '.$this->formatActor($actor);
        }

        return implode("\n", $lines);
    }

    public function enrichDateChangeDetail(
        string $label,
        string $old,
        string $new,
        ?User $actor,
        ?string $source = null,
        ?string $actorLabelOverride = null,
    ): string {
        $lines = [sprintf('%s : %s → %s', $label, $old, $new)];
        if ($source !== null && $source !== '') {
            $lines[] = 'Source : '.$source;
        }
        if ($actorLabelOverride !== null && trim($actorLabelOverride) !== '') {
            $lines[] = 'Par : '.trim($actorLabelOverride);
        } elseif ($actor !== null) {
            $lines[] = 'Par : '.$this->formatActor($actor);
        }

        return implode("\n", $lines);
    }

    /**
     * Présentation journal : extrait « Par : » et canal (Lucy / Asana).
     *
     * @param list<string> $detailLines
     *
     * @return array{facts: list<string>, actor: ?string, channel: ?string}
     */
    public function presentJournalEntry(string $actionType, ?string $userName, array $detailLines): array
    {
        $facts = [];
        $actor = null;
        $channel = $actionType === 'asana_sync' ? 'Asana' : null;

        foreach ($detailLines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^Par\s*:\s*(.+)$/ui', $trimmed, $m) === 1) {
                $raw = trim($m[1]);
                if ($raw !== '' && $raw !== '—') {
                    $actor = $raw;
                }
                continue;
            }
            if (preg_match('/^Source\s*:\s*(.+)$/ui', $trimmed, $m) === 1) {
                $source = trim($m[1]);
                if ($source !== '') {
                    $channel = $source;
                }
                continue;
            }
            $facts[] = $trimmed;
        }

        if ($actor === null && $userName !== null && trim($userName) !== '') {
            $actor = trim($userName);
            $channel ??= 'Lucy';
        }

        if ($channel === null && $actor !== null) {
            $channel = 'Lucy';
        }

        return [
            'facts' => $facts,
            'actor' => $actor,
            'channel' => $channel,
        ];
    }

    /**
     * @return list<string>
     */
    public function splitDetailLines(?string $detail): array
    {
        if ($detail === null || trim($detail) === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\r\n|\r|\n/', trim($detail)) ?: [],
            static fn (string $line): bool => trim($line) !== '',
        ));
    }
}
