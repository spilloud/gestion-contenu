<?php

namespace App\Repository;

use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * Clients triés par ordre alphabétique du nom (menus, listes, filtres).
     *
     * @return Client[]
     */
    public function findAllOrderedByClientName(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.editor', 'e')->addSelect('e')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param int[] $clientIds
     * @return Client[]
     */
    public function findByIds(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $clientIds)
            ->leftJoin('c.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.editor', 'e')->addSelect('e')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
