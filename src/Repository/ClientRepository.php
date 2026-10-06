<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\User;
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
            ->andWhere('c.isArchived = false')
            ->leftJoin('c.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.editor', 'e')->addSelect('e')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Clients triés par nom (incluant les archivés) pour l'admin.
     *
     * @return Client[]
     */
    public function findAllOrderedByClientNameIncludingArchived(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.editor', 'e')->addSelect('e')
            ->orderBy('c.isArchived', 'ASC')
            ->addOrderBy('c.name', 'ASC')
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
            ->andWhere('c.isArchived = false')
            ->leftJoin('c.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.editor', 'e')->addSelect('e')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Clients actifs (non archivés) pour le tableau « sous gestion », avec tri par colonne.
     *
     * @param 'client'|'cm'|'monteur' $sortBy
     * @param 'ASC'|'DESC'            $direction
     *
     * @return Client[]
     */
    public function findActiveForClientsTableOrdered(string $sortBy, string $direction): array
    {
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.isArchived = false')
            ->leftJoin('c.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.editor', 'e')->addSelect('e');

        if ($sortBy === 'cm') {
            $qb->orderBy('cm.name', $dir)->addOrderBy('c.name', 'ASC');
        } elseif ($sortBy === 'monteur') {
            $qb->orderBy('e.name', $dir)->addOrderBy('c.name', 'ASC');
        } else {
            $qb->orderBy('c.name', $dir);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Noms des clients actifs groupés par CM. Seuls ceux-là empêchent la
     * suppression du compte : les clients archivés sont transférés à l admin
     * qui supprime, la colonne community_manager_user_id étant NOT NULL.
     *
     * @return array<int, string[]> [id utilisateur => noms des clients actifs]
     */
    public function findActiveClientNamesGroupedByCommunityManager(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('cm.id AS cmId', 'c.name AS name')
            ->innerJoin('c.communityManager', 'cm')
            ->andWhere('c.isArchived = false')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['cmId']][] = (string) $row['name'];
        }

        return $grouped;
    }

    /**
     * Clients archivés dont cet utilisateur est la CM.
     *
     * @return Client[]
     */
    public function findArchivedByCommunityManager(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.communityManager = :user')
            ->andWhere('c.isArchived = true')
            ->setParameter('user', $user)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
