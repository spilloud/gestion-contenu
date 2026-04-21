<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\CommunityManager;
use App\Entity\Content;
use App\Entity\Format;
use App\Entity\Status;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        ContentRepository $contentRepository,
        ClientRepository $clientRepository,
    ): Response
    {
        $entityManager = $contentRepository->getEntityManager();
        $statusRepository = $entityManager->getRepository(Status::class);

        $visibleClientIds = $this->resolveVisibleClientIds($entityManager);
        $hasClientScope = $visibleClientIds !== null;

        // KPIs : on scope par utilisateur (admin = tout).
        $totalClients = $hasClientScope ? count($visibleClientIds) : $entityManager->getRepository(Client::class)->count([]);
        $totalPosts = $this->countPosts($contentRepository, $visibleClientIds);
        $totalCommunityManagers = $entityManager->getRepository(CommunityManager::class)->count([]);
        $totalStatuses = $entityManager->getRepository(Status::class)->count([]);
        $totalFormats = $entityManager->getRepository(Format::class)->count([]);

        $today = new \DateTimeImmutable('today');
        $monthStart = new \DateTimeImmutable('first day of this month');
        $monthEnd = new \DateTimeImmutable('last day of this month');
        $previousMonthStart = $monthStart->modify('-1 month');
        $previousMonthEnd = $monthStart->modify('-1 day');

        $postsThisMonthQb = $contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate >= :monthStart')
            ->andWhere('c.scheduledDate <= :monthEnd')
            ->setParameter('monthStart', $monthStart)
            ->setParameter('monthEnd', $monthEnd)
        ;
        if ($hasClientScope && $visibleClientIds !== []) {
            $postsThisMonthQb->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $visibleClientIds);
        }
        if ($hasClientScope && $visibleClientIds === []) {
            $postsThisMonth = 0;
        } else {
            $postsThisMonth = (int) $postsThisMonthQb->getQuery()->getSingleScalarResult();
        }

        $postsPreviousMonthQb = $contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate >= :monthStart')
            ->andWhere('c.scheduledDate <= :monthEnd')
            ->setParameter('monthStart', $previousMonthStart)
            ->setParameter('monthEnd', $previousMonthEnd)
        ;
        if ($hasClientScope && $visibleClientIds !== []) {
            $postsPreviousMonthQb->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $visibleClientIds);
        }
        if ($hasClientScope && $visibleClientIds === []) {
            $postsPreviousMonth = 0;
        } else {
            $postsPreviousMonth = (int) $postsPreviousMonthQb->getQuery()->getSingleScalarResult();
        }

        $publishedStatusIds = [];
        foreach ($statusRepository->findAll() as $status) {
            $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $status->getName()));
            if (str_contains($normalized, 'publie') || str_contains($normalized, 'published') || str_contains($normalized, 'online')) {
                $id = $status->getId();
                if ($id !== null) {
                    $publishedStatusIds[] = $id;
                }
            }
        }

        $publishedThisMonth = 0;
        if ($publishedStatusIds !== []) {
            $publishedThisMonthQb = $contentRepository->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->andWhere('c.scheduledDate >= :monthStart')
                ->andWhere('c.scheduledDate <= :monthEnd')
                ->andWhere('c.status IN (:publishedStatusIds)')
                ->setParameter('monthStart', $monthStart)
                ->setParameter('monthEnd', $monthEnd)
                ->setParameter('publishedStatusIds', $publishedStatusIds)
            ;
            if ($hasClientScope && $visibleClientIds !== []) {
                $publishedThisMonthQb->andWhere('c.client IN (:clientIds)')
                    ->setParameter('clientIds', $visibleClientIds);
            }
            if ($hasClientScope && $visibleClientIds === []) {
                $publishedThisMonth = 0;
            } else {
                $publishedThisMonth = (int) $publishedThisMonthQb->getQuery()->getSingleScalarResult();
            }
        }

        $overdueQb = $contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate < :today')
            ->setParameter('today', $today);
        if ($hasClientScope && $visibleClientIds !== []) {
            $overdueQb->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $visibleClientIds);
        }
        if ($publishedStatusIds !== []) {
            $overdueQb->andWhere('c.status NOT IN (:publishedStatusIds)')
                ->setParameter('publishedStatusIds', $publishedStatusIds);
        }
        $overdueCount = ($hasClientScope && $visibleClientIds === []) ? 0 : (int) $overdueQb->getQuery()->getSingleScalarResult();

        $upcomingWeekCountQb = $contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate >= :today')
            ->andWhere('c.scheduledDate <= :upcomingEnd')
            ->setParameter('today', $today)
            ->setParameter('upcomingEnd', $today->modify('+6 days'))
        ;
        if ($hasClientScope && $visibleClientIds !== []) {
            $upcomingWeekCountQb->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $visibleClientIds);
        }
        $upcomingWeekCount = ($hasClientScope && $visibleClientIds === []) ? 0 : (int) $upcomingWeekCountQb->getQuery()->getSingleScalarResult();

        $completionRate = $postsThisMonth > 0 ? (int) round(($publishedThisMonth / $postsThisMonth) * 100) : 0;
        $volumeTrendPercent = $postsPreviousMonth > 0
            ? (int) round((($postsThisMonth - $postsPreviousMonth) / $postsPreviousMonth) * 100)
            : null;

        $contentsThisMonth = $contentRepository->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('cl.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->leftJoin('c.format', 'f')->addSelect('f')
            ->andWhere('c.scheduledDate >= :monthStart')
            ->andWhere('c.scheduledDate <= :monthEnd')
            ->setParameter('monthStart', $monthStart)
            ->setParameter('monthEnd', $monthEnd)
        ;
        if ($hasClientScope && $visibleClientIds !== []) {
            $contentsThisMonth->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $visibleClientIds);
        }
        $contentsThisMonth = ($hasClientScope && $visibleClientIds === []) ? [] : $contentsThisMonth
            ->orderBy('c.scheduledDate', 'ASC')
            ->getQuery()
            ->getResult();

        $weekStart = $monthStart->modify('monday this week');
        $monthLastWeekStart = $monthEnd->modify('monday this week');
        $weeklyLabels = [];
        $weeklyPlanned = [];
        $weeklyPublished = [];
        $weeklyOverdue = [];
        $weekIndexByStart = [];
        $i = 0;
        while ($weekStart <= $monthLastWeekStart) {
            $key = $weekStart->format('Y-m-d');
            $weeklyLabels[] = sprintf('S%d (%s)', $i + 1, $weekStart->format('d/m'));
            $weeklyPlanned[] = 0;
            $weeklyPublished[] = 0;
            $weeklyOverdue[] = 0;
            $weekIndexByStart[$key] = $i;
            $weekStart = $weekStart->modify('+7 days');
            $i++;
        }

        $topClientsCount = [];
        $statusDistribution = [];
        $statusColorMap = [
            'gray' => '#95a5a6',
            'taupe' => '#8b7d70',
            'canard' => '#0f6b6f',
            'violet' => '#6d28d9',
            'bleu-nuit' => '#1e3a8a',
            'ardoise' => '#475569',
            'green' => '#27ae60',
            'lightgreen' => '#2ecc71',
            'sauge' => '#7c9a92',
            'menthe' => '#34d399',
            'yellow' => '#f1c40f',
            'moutarde' => '#cfa500',
            'orange' => '#e67e22',
            'corail' => '#fb7185',
            'red' => '#e74c3c',
            'framboise' => '#be185d',
            'rose-poudre' => '#f9a8d4',
            'fuchsia' => '#d946ef',
        ];

        foreach ($contentsThisMonth as $content) {
            $scheduledDate = $content->getScheduledDate();
            if (!$scheduledDate instanceof \DateTimeInterface) {
                continue;
            }
            $scheduled = \DateTimeImmutable::createFromInterface($scheduledDate);
            $contentWeekStart = $scheduled->modify('monday this week')->format('Y-m-d');
            if (isset($weekIndexByStart[$contentWeekStart])) {
                $weekIndex = $weekIndexByStart[$contentWeekStart];
                $weeklyPlanned[$weekIndex]++;
                $statusId = $content->getStatus()?->getId();
                $isPublished = $statusId !== null && in_array($statusId, $publishedStatusIds, true);
                if ($isPublished) {
                    $weeklyPublished[$weekIndex]++;
                }
                if ($scheduled < $today && !$isPublished) {
                    $weeklyOverdue[$weekIndex]++;
                }
            }

            $clientName = $content->getClient()?->getName() ?? 'Sans client';
            $topClientsCount[$clientName] = ($topClientsCount[$clientName] ?? 0) + 1;

            $statusName = $content->getStatus()?->getName() ?? 'Sans statut';
            $statusColor = (string) ($content->getStatus()?->getColor() ?? 'gray');
            if (!str_starts_with($statusColor, '#')) {
                $statusColor = $statusColorMap[$statusColor] ?? '#64748b';
            }
            if (!isset($statusDistribution[$statusName])) {
                $statusDistribution[$statusName] = ['count' => 0, 'color' => $statusColor];
            }
            $statusDistribution[$statusName]['count']++;
        }

        arsort($topClientsCount);
        $topClients = array_slice($topClientsCount, 0, 5, true);

        $overdueItemsQb = $contentRepository->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('cl.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->andWhere('c.scheduledDate < :today')
            ->setParameter('today', $today)
            ->orderBy('c.scheduledDate', 'ASC')
            ->setMaxResults(8);
        if ($hasClientScope && $visibleClientIds !== []) {
            $overdueItemsQb->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $visibleClientIds);
        }
        if ($publishedStatusIds !== []) {
            $overdueItemsQb->andWhere('c.status NOT IN (:publishedStatusIds)')
                ->setParameter('publishedStatusIds', $publishedStatusIds);
        }
        $overdueItems = ($hasClientScope && $visibleClientIds === []) ? [] : $overdueItemsQb->getQuery()->getResult();

        $todayItems = $contentRepository->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('cl.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->andWhere('c.scheduledDate = :today')
            ->setParameter('today', $today)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(8)
        ;
        if ($hasClientScope && $visibleClientIds !== []) {
            $todayItems->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $visibleClientIds);
        }
        $todayItems = ($hasClientScope && $visibleClientIds === []) ? [] : $todayItems->getQuery()->getResult();

        $upcomingItems = $contentRepository->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('cl.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->andWhere('c.scheduledDate > :today')
            ->andWhere('c.scheduledDate <= :soon')
            ->setParameter('today', $today)
            ->setParameter('soon', $today->modify('+7 days'))
            ->orderBy('c.scheduledDate', 'ASC')
            ->setMaxResults(8)
        ;
        if ($hasClientScope && $visibleClientIds !== []) {
            $upcomingItems->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $visibleClientIds);
        }
        $upcomingItems = ($hasClientScope && $visibleClientIds === []) ? [] : $upcomingItems->getQuery()->getResult();

        // --- Colonnes vidéo (to-do) ---
        $videoFormat = $this->findVideoFormat($entityManager);
        $statusMontageAFaire = $statusRepository->findOneBy(['name' => 'Montage à faire']);
        $statusMontageAControler = $statusRepository->findOneBy(['name' => 'À valider (Prod)']);
        $statusSousTitresRelire = $statusRepository->findOneBy(['name' => 'Sous-titres à valider']);

        $montagesAFaire = $this->findDashboardVideoItems($contentRepository, $videoFormat, $statusMontageAFaire, $visibleClientIds, 12);
        $montagesAControler = $this->findDashboardVideoItems($contentRepository, $videoFormat, $statusMontageAControler, $visibleClientIds, 12);
        $soustitresARelire = $this->findDashboardVideoItems($contentRepository, $videoFormat, $statusSousTitresRelire, $visibleClientIds, 12);

        return $this->render('dashboard/index.html.twig', [
            'totalClients' => $totalClients,
            'totalPosts' => $totalPosts,
            'totalCommunityManagers' => $totalCommunityManagers,
            'totalStatuses' => $totalStatuses,
            'totalFormats' => $totalFormats,
            'postsThisMonth' => $postsThisMonth,
            'publishedThisMonth' => $publishedThisMonth,
            'completionRate' => $completionRate,
            'overdueCount' => $overdueCount,
            'upcomingWeekCount' => $upcomingWeekCount,
            'volumeTrendPercent' => $volumeTrendPercent,
            'weeklyLabels' => $weeklyLabels,
            'weeklyPlanned' => $weeklyPlanned,
            'weeklyPublished' => $weeklyPublished,
            'weeklyOverdue' => $weeklyOverdue,
            'topClientLabels' => array_keys($topClients),
            'topClientValues' => array_values($topClients),
            'statusLabels' => array_keys($statusDistribution),
            'statusValues' => array_map(static fn (array $v): int => (int) $v['count'], array_values($statusDistribution)),
            'statusColors' => array_map(static fn (array $v): string => (string) $v['color'], array_values($statusDistribution)),
            'overdueItems' => $overdueItems,
            'todayItems' => $todayItems,
            'upcomingItems' => $upcomingItems,
            'montagesAFaire' => $montagesAFaire,
            'montagesAControler' => $montagesAControler,
            'soustitresARelire' => $soustitresARelire,
        ]);
    }

    /**
     * @return int[]|null Null = pas de filtre (admin)
     */
    private function resolveVisibleClientIds(EntityManagerInterface $entityManager): ?array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $roles = $user->getRoles();
        if (in_array(User::ROLE_ADMIN, $roles, true)) {
            return null;
        }

        if (in_array(User::ROLE_EDITOR, $roles, true)) {
            $rows = $entityManager->createQueryBuilder()
                ->select('c.id')
                ->from(Client::class, 'c')
                ->andWhere('c.editor = :editor')
                ->setParameter('editor', $user)
                ->orderBy('c.name', 'ASC')
                ->getQuery()
                ->getArrayResult();

            return array_map(static fn (array $r): int => (int) $r['id'], $rows);
        }

        // CM : match via email (User.email == CommunityManager.email)
        $cm = $entityManager->getRepository(CommunityManager::class)->findOneBy([
            'email' => $user->getUserIdentifier(),
        ]);
        if (!$cm instanceof CommunityManager) {
            return [];
        }

        $rows = $entityManager->createQueryBuilder()
            ->select('c.id')
            ->from(Client::class, 'c')
            ->andWhere('c.communityManager = :cm')
            ->setParameter('cm', $cm)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    private function countPosts(ContentRepository $repo, ?array $clientIds): int
    {
        $qb = $repo->createQueryBuilder('c')
            ->select('COUNT(c.id)');
        if ($clientIds !== null) {
            if ($clientIds === []) {
                return 0;
            }
            $qb->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $clientIds);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function findVideoFormat(EntityManagerInterface $entityManager): ?Format
    {
        $formats = $entityManager->getRepository(Format::class)->findAll();
        foreach ($formats as $format) {
            $name = mb_strtolower(trim((string) $format->getName()));
            if ($name === 'vidéo' || $name === 'video') {
                return $format;
            }
        }

        return null;
    }

    /**
     * @return Content[]
     */
    private function findDashboardVideoItems(
        ContentRepository $repo,
        ?Format $videoFormat,
        ?Status $status,
        ?array $clientIds,
        int $limit
    ): array {
        if ($videoFormat === null || $status === null) {
            return [];
        }
        if ($clientIds !== null && $clientIds === []) {
            return [];
        }

        $qb = $repo->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('cl.communityManager', 'cm')->addSelect('cm')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->leftJoin('c.videoEditor', 'e')->addSelect('e')
            ->andWhere('c.format = :format')
            ->andWhere('c.status = :status')
            ->setParameter('format', $videoFormat)
            ->setParameter('status', $status)
            ->orderBy('c.scheduledDate', 'ASC')
            ->addOrderBy('cl.name', 'ASC')
            ->setMaxResults($limit);

        if ($clientIds !== null) {
            $qb->andWhere('c.client IN (:clientIds)')
                ->setParameter('clientIds', $clientIds);
        }

        return $qb->getQuery()->getResult();
    }
}
