<?php

namespace App\Service;

use App\Entity\Content;

/**
 * Date d'échéance montage (tâche Asana) : saisie CM ou défaut publication − 3 jours.
 */
final class VideoMontageDueOnResolver
{
    public const MONTAGE_LEAD_DAYS = 3;

    public function resolveForContent(Content $content): \DateTimeImmutable
    {
        $stored = $content->getAsanaMontageDueOn();
        if ($stored !== null) {
            return $stored;
        }

        return $this->defaultFromPublication($content->getScheduledDate());
    }

    public function defaultFromPublication(?\DateTimeInterface $publication): \DateTimeImmutable
    {
        if ($publication !== null) {
            $immutable = $publication instanceof \DateTimeImmutable
                ? $publication
                : \DateTimeImmutable::createFromInterface($publication);

            return $immutable->modify('-'.self::MONTAGE_LEAD_DAYS.' days');
        }

        return (new \DateTimeImmutable('today'))->modify('+2 days');
    }

    public function parseOptional(?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function parseOrDefault(?string $value, Content $content): \DateTimeImmutable
    {
        return $this->parseOptional($value) ?? $this->resolveForContent($content);
    }
}
