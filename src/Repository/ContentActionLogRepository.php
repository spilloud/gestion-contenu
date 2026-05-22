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
            ])
            ->orderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
