<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Content;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Content>
 */
class ContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Content::class);
    }

    /**
     * @param int[]|null $clientIds
     * @param int[]|null $statusIds
     * @param int[]|null $formatIds
     * @param \DateTimeInterface|null $monthStart
     * @param \DateTimeInterface|null $monthEnd
     * @return Content[]
     */
    public function findByFilters(
        ?array $clientIds = null,
        ?array $statusIds = null,
        ?array $formatIds = null,
        ?\DateTimeInterface $monthStart = null,
        ?\DateTimeInterface $monthEnd = null
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')
            ->leftJoin('cl.communityManager', 'cm')
            ->addSelect('cl', 'cm')
            ->leftJoin('c.status', 's')
            ->leftJoin('c.format', 'f')
            ->addSelect('s', 'f');

        if (!empty($clientIds)) {
            $qb->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $clientIds);
        }

        if (!empty($statusIds)) {
            $qb->andWhere('c.status IN (:statusIds)')
                ->setParameter('statusIds', $statusIds);
        }

        if (!empty($formatIds)) {
            $qb->andWhere('c.format IN (:formatIds)')
                ->setParameter('formatIds', $formatIds);
        }

        if ($monthStart !== null) {
            $qb->andWhere('c.scheduledDate >= :monthStart')
                ->setParameter('monthStart', $monthStart);
        }

        if ($monthEnd !== null) {
            $qb->andWhere('c.scheduledDate <= :monthEnd')
                ->setParameter('monthEnd', $monthEnd);
        }

        return $qb
            ->addOrderBy('c.scheduledDate', 'ASC')
            ->addOrderBy('cm.name', 'ASC')
            ->addOrderBy('cl.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Content[]
     */
    public function findByClientAndArchiveState(Client $client, bool $archives): array
    {
        $today = new \DateTimeImmutable('today');

        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.format', 'f')
            ->leftJoin('c.status', 's')
            ->addSelect('f', 's')
            ->andWhere('c.client = :client')
            ->setParameter('client', $client)
            ->setParameter('today', $today);

        if ($archives) {
            $qb->andWhere('c.scheduledDate < :today')
                ->orderBy('c.scheduledDate', 'DESC');
        } else {
            $qb->andWhere('c.scheduledDate >= :today')
                ->orderBy('c.scheduledDate', 'ASC');
        }

        return $qb->getQuery()->getResult();
    }
}
