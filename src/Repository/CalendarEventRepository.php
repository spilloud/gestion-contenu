<?php

namespace App\Repository;

use App\Entity\CalendarEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarEvent>
 */
class CalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    /**
     * @param int[]|null $clientIds  Filtre clients du calendrier (ids cochés). null = pas de filtre.
     * @param int|null $restrictToClientId  Fiche client : globaux + ce client uniquement.
     *
     * @return CalendarEvent[]
     */
    public function findForCalendarRange(
        \DateTimeInterface $rangeStart,
        \DateTimeInterface $rangeEnd,
        ?array $clientIds = null,
        ?int $restrictToClientId = null,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.client', 'cl')
            ->addSelect('cl')
            ->andWhere('e.endDate >= :rangeStart')
            ->andWhere('e.startDate <= :rangeEnd')
            ->setParameter('rangeStart', $rangeStart)
            ->setParameter('rangeEnd', $rangeEnd)
            ->orderBy('e.startDate', 'ASC')
            ->addOrderBy('e.title', 'ASC');

        if ($restrictToClientId !== null) {
            $qb->andWhere('e.client IS NULL OR e.client = :restrictClient')
                ->setParameter('restrictClient', $restrictToClientId);
        } elseif ($clientIds !== null) {
            if ($clientIds === []) {
                $qb->andWhere('e.client IS NULL');
            } else {
                $qb->andWhere('e.client IS NULL OR e.client IN (:clientIds)')
                    ->setParameter('clientIds', $clientIds);
            }
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return CalendarEvent[]
     */
    public function findAllForManagement(): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.client', 'cl')
            ->addSelect('cl')
            ->orderBy('e.startDate', 'DESC')
            ->addOrderBy('e.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
