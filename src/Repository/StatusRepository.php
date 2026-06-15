<?php

namespace App\Repository;

use App\Entity\Status;
use App\Workflow\ContentWorkflowRegistry;
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

    /**
     * Liste courte alignée sur le parcours métier (menus, filtres).
     * Inclut le statut actuel s'il est legacy / obsolète (fiche existante).
     *
     * @return Status[]
     */
    public function findSelectableForWorkflow(string $workflow, ?Status $ensureIncluded = null): array
    {
        $names = ContentWorkflowRegistry::selectableStatusNames($workflow);
        if ($ensureIncluded?->getName() !== null && !in_array($ensureIncluded->getName(), $names, true)) {
            $names[] = $ensureIncluded->getName();
        }

        if ($names === []) {
            return [];
        }

        /** @var Status[] $statuses */
        $statuses = $this->createQueryBuilder('s')
            ->andWhere('s.name IN (:names)')
            ->setParameter('names', $names)
            ->getQuery()
            ->getResult();

        $byName = [];
        foreach ($statuses as $status) {
            $byName[$status->getName() ?? ''] = $status;
        }

        $ordered = [];
        foreach ($names as $name) {
            if (isset($byName[$name])) {
                $ordered[] = $byName[$name];
            }
        }

        return $ordered;
    }

    public function findOneByName(string $name): ?Status
    {
        return $this->findOneBy(['name' => $name]);
    }
}
