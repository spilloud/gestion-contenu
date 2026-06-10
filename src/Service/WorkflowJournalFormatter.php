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

    public function enrichTransitionDetail(Content $content, string $from, string $to, ?User $actor): string
    {
        $lines = [sprintf('%s → %s', $from, $to)];
        $lines[] = 'Par : '.$this->formatActor($actor);

        return implode("\n", $lines);
    }

    public function enrichDelegationDetail(string $label, ?User $previous, ?User $next, ?User $actor): string
    {
        $old = $previous instanceof User ? $this->formatActor($previous) : '—';
        $new = $next instanceof User ? $this->formatActor($next) : '—';

        $lines = [
            sprintf('%s : %s → %s', $label, $old, $new),
        ];
        if ($actor !== null) {
            $lines[] = 'Par : '.$this->formatActor($actor);
        }

        return implode("\n", $lines);
    }

    public function enrichDateChangeDetail(string $label, string $old, string $new, ?User $actor, ?string $source = null): string
    {
        $lines = [sprintf('%s : %s → %s', $label, $old, $new)];
        if ($source !== null && $source !== '') {
            $lines[] = 'Source : '.$source;
        }
        if ($actor !== null) {
            $lines[] = 'Par : '.$this->formatActor($actor);
        }

        return implode("\n", $lines);
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
