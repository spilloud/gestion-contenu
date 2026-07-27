<?php

namespace App\Repository;

use App\Entity\Campaign;
use App\Entity\CampaignCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CampaignCategory>
 */
class CampaignCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampaignCategory::class);
    }

    /**
     * @return CampaignCategory[]
     */
    public function findOrderedForCampaign(Campaign $campaign): array
    {
        return $this->createQueryBuilder('cat')
            ->andWhere('cat.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->orderBy('cat.sortOrder', 'ASC')
            ->addOrderBy('cat.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function nextSortOrder(Campaign $campaign): int
    {
        $max = $this->createQueryBuilder('cat')
            ->select('MAX(cat.sortOrder)')
            ->andWhere('cat.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 10;
    }
}
