<?php

namespace App\Repository;

use App\Entity\Status;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Status>
 */
class StatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Status::class);
    }

    /**
     * @return Status[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Status[]
     */
    public function findForWorkflow(string $workflow): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.workflow IN (:workflows)')
            ->setParameter('workflows', [$workflow, Status::WORKFLOW_BOTH])
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByName(string $name): ?Status
    {
        return $this->findOneBy(['name' => $name]);
    }
}
