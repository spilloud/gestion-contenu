<?php

namespace App\Repository;

use App\Entity\Content;
use App\Entity\ContentActionLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentActionLog>
 */
class ContentActionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentActionLog::class);
    }

    /**
     * Journal visible sur la fiche (parcours statuts / actions métier).
     *
     * @return ContentActionLog[]
     */
    public function findVisibleJourneyForContent(Content $content): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.content = :content')
            ->andWhere('l.actionType IN (:types)')
            ->setParameter('content', $content)
            ->setParameter('types', [
                ContentActionLog::TYPE_CREATED,
                ContentActionLog::TYPE_STATUS_CHANGED,
                ContentActionLog::TYPE_TRANSITION,
                ContentActionLog::TYPE_STEP_BACK,
                ContentActionLog::TYPE_MANUAL_STATUS,
                ContentActionLog::TYPE_EDITOR_CHANGED,
                ContentActionLog::TYPE_CM_USER_CHANGED,
                ContentActionLog::TYPE_SCHEDULED_DATE_CHANGED,
                ContentActionLog::TYPE_ASANA_SYNC,
            ])
            ->orderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Statut précédent d'après le journal (gère les branches avec/sans sous-titres).
     */
    public function resolvePreviousStatusName(Content $content): ?string
    {
        $current = $content->getStatus()?->getName();
        if ($current === null || $current === '') {
            return null;
        }

        $logs = $this->findVisibleJourneyForContent($content);
        if ($logs === []) {
            return null;
        }

        foreach (array_reverse($logs) as $log) {
            if (!in_array($log->getActionType(), [
                ContentActionLog::TYPE_TRANSITION,
                ContentActionLog::TYPE_MANUAL_STATUS,
                ContentActionLog::TYPE_STATUS_CHANGED,
            ], true)) {
                continue;
            }

            $parsed = self::parseStatusChangeDetail($log->getDetail());
            if ($parsed === null) {
                continue;
            }

            [$from, $to] = $parsed;
            if ($to === $current && $from !== '' && $from !== $current) {
                return $from;
            }
        }

        return null;
    }

    /**
     * La vidéo est-elle passée par ce statut (enregistré comme destination d'une transition) ?
     */
    public function hasTransitionToStatus(Content $content, string $statusName): bool
    {
        foreach ($this->findVisibleJourneyForContent($content) as $log) {
            if (!in_array($log->getActionType(), [
                ContentActionLog::TYPE_TRANSITION,
                ContentActionLog::TYPE_MANUAL_STATUS,
                ContentActionLog::TYPE_STATUS_CHANGED,
            ], true)) {
                continue;
            }

            $parsed = self::parseStatusChangeDetail($log->getDetail());
            if ($parsed === null) {
                continue;
            }

            [, $to] = $parsed;
            if ($to === $statusName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}|null [from, to]
     */
    public static function parseStatusChangeDetail(?string $detail): ?array
    {
        if ($detail === null || trim($detail) === '') {
            return null;
        }

        $detail = trim($detail);
        $firstLine = trim(strtok($detail, "\r\n"));

        if ($firstLine === '' || preg_match('/^(.+?)\s*→\s*(.+?)(?:\s*\([^)]*\))?\s*$/u', $firstLine, $matches) !== 1) {
            return null;
        }

        $from = trim($matches[1]);
        $to = trim($matches[2]);

        if ($from === '' || $to === '') {
            return null;
        }

        return [$from, $to];
    }
}
