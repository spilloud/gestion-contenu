<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\User;

/**
 * Formate les entrées du journal de parcours (acteur, login, délégation).
 */
final class WorkflowJournalFormatter
{
    public function __construct(
        private readonly ContentFormatHelper $formatHelper,
        private readonly VideoAssigneeResolver $assigneeResolver,
    ) {
    }

    public function formatActor(?User $user): string
    {
        if ($user === null) {
            return '—';
        }

        $name = trim((string) ($user->getName() ?? ''));
        $email = trim((string) ($user->getEmail() ?? ''));

        if ($name !== '' && $email !== '') {
            return $name.' ('.$email.')';
        }

        return $name !== '' ? $name : ($email !== '' ? $email : '—');
    }

    public function enrichTransitionDetail(Content $content, string $from, string $to, ?User $actor): string
    {
        $lines = [sprintf('%s → %s', $from, $to)];
        $lines[] = 'Par : '.$this->formatActor($actor);

        if (!$this->formatHelper->isVideoContent($content)) {
            return implode("\n", $lines);
        }

        $monteur = $content->getVideoEditor();
        $cm = $this->assigneeResolver->resolveCommunityManagerForDisplay($content);

        if ($this->isMontageRelated($from, $to) && $monteur !== null) {
            $lines[] = 'Monteur assigné : '.$this->formatActor($monteur);
            if ($actor !== null && $monteur->getId() !== $actor->getId()) {
                $lines[] = 'Délégation : '.$this->formatActor($actor).' fait avancer pour le monteur';
            }
        }

        if ($this->isCmRelated($from, $to) && $cm !== null) {
            $lines[] = 'CM assignée : '.$this->formatActor($cm);
            if ($actor !== null && $cm->getId() !== $actor->getId()) {
                $lines[] = 'Délégation : '.$this->formatActor($actor).' fait avancer pour la CM';
            }
        }

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

    private function isMontageRelated(string $from, string $to): bool
    {
        $montageStatuses = [
            'Montage à faire',
            'Montage en cours',
            'Retouches (Monteur)',
            'À valider (Prod)',
        ];

        return in_array($from, $montageStatuses, true) || in_array($to, $montageStatuses, true);
    }

    private function isCmRelated(string $from, string $to): bool
    {
        $cmStatuses = [
            'Sous-titrage (SubMagic)',
            'Sous-titres à valider',
            'À valider (CM)',
            'Prépa CM (sans sous-titres)',
        ];

        return in_array($from, $cmStatuses, true) || in_array($to, $cmStatuses, true);
    }
}
