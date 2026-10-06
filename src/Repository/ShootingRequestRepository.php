<?php

namespace App\Repository;

use App\Entity\ShootingRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShootingRequest>
 */
class ShootingRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShootingRequest::class);
    }

    /**
     * @return ShootingRequest[]
     */
    public function findAllForList(): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.client', 'c')->addSelect('c')
            ->leftJoin('s.assignedTo', 'a')->addSelect('a')
            ->leftJoin('s.createdBy', 'u')->addSelect('u')
            ->leftJoin('s.videos', 'v')->addSelect('v')
            ->orderBy('s.shootingDate', 'DESC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForShow(int $id): ?ShootingRequest
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.client', 'c')->addSelect('c')
            ->leftJoin('s.assignedTo', 'a')->addSelect('a')
            ->leftJoin('s.createdBy', 'u')->addSelect('u')
            ->leftJoin('s.videos', 'v')->addSelect('v')
            ->andWhere('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return ShootingRequest[]
     */
    public function findWithOpenAsanaTask(): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.client', 'c')->addSelect('c')
            ->leftJoin('s.assignedTo', 'a')->addSelect('a')
            ->leftJoin('s.videos', 'v')->addSelect('v')
            ->andWhere('s.asanaTaskGid IS NOT NULL')
            ->andWhere('s.asanaTaskCompletedAt IS NULL')
            ->orderBy('s.shootingDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de demandes de tournage par utilisateur assigné.
     *
     * shooting_request.assigned_to_id est NOT NULL + ON DELETE RESTRICT : toute
     * demande encore assignée empêche la suppression du compte. Comptage côté
     * PHP (volume faible) pour rester sur du DQL trivial.
     *
     * @return array<int, int> [id utilisateur => nombre de demandes]
     */
    public function countGroupedByAssignee(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('a.id AS userId')
            ->innerJoin('s.assignedTo', 'a')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $userId = (int) $row['userId'];
            $counts[$userId] = ($counts[$userId] ?? 0) + 1;
        }

        return $counts;
    }
}
