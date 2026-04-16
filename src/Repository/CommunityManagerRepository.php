<?php

namespace App\Repository;

use App\Entity\CommunityManager;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommunityManager>
 */
class CommunityManagerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommunityManager::class);
    }

    /**
     * @return CommunityManager[]
     */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('cm')
            ->orderBy('cm.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
