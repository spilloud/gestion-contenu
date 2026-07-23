<?php

namespace App\Repository;

use App\Entity\AsanaLinkedTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AsanaLinkedTask>
 */
class AsanaLinkedTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AsanaLinkedTask::class);
    }

    /**
     * @return AsanaLinkedTask[]
     */
    public function findOpenTasks(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.client', 'c')->addSelect('c')
            ->andWhere('t.completedAtLucy IS NULL')
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
