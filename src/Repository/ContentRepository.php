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
     * @param bool                  $withTeamComments Si true : joint les commentaires (retours équipe) pour le rapport / évite le chargement paresseux ligne à ligne.
     * @return Content[]
     */
    public function findByFilters(
        ?array $clientIds = null,
        ?array $statusIds = null,
        ?array $formatIds = null,
        ?\DateTimeInterface $monthStart = null,
        ?\DateTimeInterface $monthEnd = null,
        bool $withTeamComments = false,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')
            ->leftJoin('cl.communityManager', 'cm')
            ->addSelect('cl', 'cm')
            ->leftJoin('c.status', 's')
            ->leftJoin('c.format', 'f')
            ->addSelect('s', 'f');

        if ($withTeamComments) {
            // Pas de distinct() : sous PostgreSQL, DISTINCT sur une requête qui inclut
            // l’entité User (colonne roles en json) provoque SQLSTATE[42883].
            // L’hydratation Doctrine déduplique déjà les Content par identifiant.
            $qb->leftJoin('c.comments', 'com')->addSelect('com')
                ->leftJoin('com.author', 'cca')->addSelect('cca');
        }

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
     * Tous les contenus planifiés du client sur une année (passés, brouillons, tous statuts).
     *
     * @return Content[]
     */
    public function findByClientForYearPlanning(
        Client $client,
        \DateTimeInterface $yearStart,
        \DateTimeInterface $yearEnd,
    ): array {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->leftJoin('c.format', 'f')->addSelect('f')
            ->andWhere('c.client = :client')
            ->andWhere('c.scheduledDate >= :yearStart')
            ->andWhere('c.scheduledDate <= :yearEnd')
            ->setParameter('client', $client)
            ->setParameter('yearStart', $yearStart)
            ->setParameter('yearEnd', $yearEnd)
            ->orderBy('c.scheduledDate', 'ASC')
            ->addOrderBy('c.title', 'ASC')
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
