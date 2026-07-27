<?php

namespace App\Repository;

use App\Entity\Campaign;
use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Campaign>
 */
class CampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Campaign::class);
    }

    /**
     * Campagne dont la période couvre aujourd’hui (au plus une attendue).
     */
    public function findCurrentForClient(Client $client, ?\DateTimeInterface $today = null): ?Campaign
    {
        if ($today instanceof \DateTimeImmutable) {
            $day = $today->setTime(0, 0);
        } elseif ($today instanceof \DateTimeInterface) {
            $day = \DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
        } else {
            $day = new \DateTimeImmutable('today');
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.client = :client')
            ->andWhere('c.startsOn <= :today')
            ->andWhere('c.endsOn >= :today')
            ->setParameter('client', $client)
            ->setParameter('today', $day)
            ->orderBy('c.startsOn', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Campagne la plus pertinente pour la vue : courante, sinon la plus récente.
     */
    public function findPreferredForClient(Client $client): ?Campaign
    {
        $current = $this->findCurrentForClient($client);
        if ($current !== null) {
            return $current;
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.client = :client')
            ->setParameter('client', $client)
            ->orderBy('c.endsOn', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Campaign[]
     */
    public function findOverlapping(Client $client, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, ?Campaign $exclude = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.client = :client')
            ->andWhere('c.startsOn <= :endsOn')
            ->andWhere('c.endsOn >= :startsOn')
            ->setParameter('client', $client)
            ->setParameter('startsOn', $startsOn)
            ->setParameter('endsOn', $endsOn);

        if ($exclude?->getId() !== null) {
            $qb->andWhere('c.id != :excludeId')
                ->setParameter('excludeId', $exclude->getId());
        }

        return $qb->getQuery()->getResult();
    }
}
