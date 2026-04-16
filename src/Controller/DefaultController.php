<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\CommunityManager;
use App\Entity\Content;
use App\Entity\Format;
use App\Entity\Status;
use App\Repository\ContentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        ContentRepository $contentRepository,
    ): Response
    {
        $entityManager = $contentRepository->getEntityManager();
        $statusRepository = $entityManager->getRepository(Status::class);

        $totalClients = $entityManager->getRepository(Client::class)->count([]);
        $totalPosts = $entityManager->getRepository(Content::class)->count([]);
        $totalCommunityManagers = $entityManager->getRepository(CommunityManager::class)->count([]);
        $totalStatuses = $entityManager->getRepository(Status::class)->count([]);
        $totalFormats = $entityManager->getRepository(Format::class)->count([]);

        $today = new \DateTimeImmutable('today');
        $monthStart = new \DateTimeImmutable('first day of this month');
        $monthEnd = new \DateTimeImmutable('last day of this month');
        $previousMonthStart = $monthStart->modify('-1 month');
        $previousMonthEnd = $monthStart->modify('-1 day');

        $postsThisMonth = (int) $contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate >= :monthStart')
            ->andWhere('c.scheduledDate <= :monthEnd')
            ->setParameter('monthStart', $monthStart)
            ->setParameter('monthEnd', $monthEnd)
            ->getQuery()
            ->getSingleScalarResult();

        $postsPreviousMonth = (int) $contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate >= :monthStart')
            ->andWhere('c.scheduledDate <= :monthEnd')
            ->setParameter('monthStart', $previousMonthStart)
            ->setParameter('monthEnd', $previousMonthEnd)
            ->getQuery()
            ->getSingleScalarResult();

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
            $publishedThisMonth = (int) $contentRepository->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->andWhere('c.scheduledDate >= :monthStart')
                ->andWhere('c.scheduledDate <= :monthEnd')
                ->andWhere('c.status IN (:publishedStatusIds)')
                ->setParameter('monthStart', $monthStart)
                ->setParameter('monthEnd', $monthEnd)
                ->setParameter('publishedStatusIds', $publishedStatusIds)
                ->getQuery()
                ->getSingleScalarResult();
        }

        $overdueQb = $contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate < :today')
            ->setParameter('today', $today);
        if ($publishedStatusIds !== []) {
            $overdueQb->andWhere('c.status NOT IN (:publishedStatusIds)')
                ->setParameter('publishedStatusIds', $publishedStatusIds);
        }
        $overdueCount = (int) $overdueQb->getQuery()->getSingleScalarResult();

        $upcomingWeekCount = (int) $contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate >= :today')
            ->andWhere('c.scheduledDate <= :upcomingEnd')
            ->setParameter('today', $today)
            ->setParameter('upcomingEnd', $today->modify('+6 days'))
            ->getQuery()
            ->getSingleScalarResult();

        $completionRate = $postsThisMonth > 0 ? (int) round(($publishedThisMonth / $postsThisMonth) * 100) : 0;
        $volumeTrendPercent = $postsPreviousMonth > 0
            ? (int) round((($postsThisMonth - $postsPreviousMonth) / $postsPreviousMonth) * 100)
            : null;

        $contentsThisMonth = $contentRepository->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->leftJoin('c.format', 'f')->addSelect('f')
            ->andWhere('c.scheduledDate >= :monthStart')
            ->andWhere('c.scheduledDate <= :monthEnd')
            ->setParameter('monthStart', $monthStart)
            ->setParameter('monthEnd', $monthEnd)
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
            ->leftJoin('c.status', 's')->addSelect('s')
            ->andWhere('c.scheduledDate < :today')
            ->setParameter('today', $today)
            ->orderBy('c.scheduledDate', 'ASC')
            ->setMaxResults(8);
        if ($publishedStatusIds !== []) {
            $overdueItemsQb->andWhere('c.status NOT IN (:publishedStatusIds)')
                ->setParameter('publishedStatusIds', $publishedStatusIds);
        }
        $overdueItems = $overdueItemsQb->getQuery()->getResult();

        $todayItems = $contentRepository->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->andWhere('c.scheduledDate = :today')
            ->setParameter('today', $today)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();

        $upcomingItems = $contentRepository->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->andWhere('c.scheduledDate > :today')
            ->andWhere('c.scheduledDate <= :soon')
            ->setParameter('today', $today)
            ->setParameter('soon', $today->modify('+7 days'))
            ->orderBy('c.scheduledDate', 'ASC')
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();

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
        ]);
    }
}
