<?php

namespace App\Repository;

use App\Entity\AiApiConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiApiConfig>
 */
class AiApiConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiApiConfig::class);
    }

    public function getSingleton(): AiApiConfig
    {
        $row = $this->find(AiApiConfig::SINGLETON_ID);
        if ($row instanceof AiApiConfig) {
            return $row;
        }

        $row = new AiApiConfig();
        $this->getEntityManager()->persist($row);
        $this->getEntityManager()->flush();

        return $row;
    }
}
