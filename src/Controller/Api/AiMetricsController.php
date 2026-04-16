<?php

namespace App\Controller\Api;

use App\Entity\Status;
use App\Repository\ContentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/ai')]
class AiMetricsController extends AbstractController
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
    ) {
    }

    #[Route('/dashboard-kpi', name: 'app_api_ai_dashboard_kpi', methods: ['GET'])]
    public function dashboardKpi(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        if (!$this->isAllowedIp($request)) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $entityManager = $this->contentRepository->getEntityManager();
        $statusRepository = $entityManager->getRepository(Status::class);

        $today = new \DateTimeImmutable('today');
        $monthStart = new \DateTimeImmutable('first day of this month');
        $monthEnd = new \DateTimeImmutable('last day of this month');

        $publishedStatusIds = [];
        foreach ($statusRepository->findAll() as $status) {
            $statusName = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $status->getName()));
            if (str_contains($statusName, 'publie') || str_contains($statusName, 'published') || str_contains($statusName, 'online')) {
                $statusId = $status->getId();
                if ($statusId !== null) {
                    $publishedStatusIds[] = $statusId;
                }
            }
        }

        $postsThisMonth = (int) $this->contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate >= :monthStart')
            ->andWhere('c.scheduledDate <= :monthEnd')
            ->setParameter('monthStart', $monthStart)
            ->setParameter('monthEnd', $monthEnd)
            ->getQuery()
            ->getSingleScalarResult();

        $publishedThisMonth = 0;
        if ($publishedStatusIds !== []) {
            $publishedThisMonth = (int) $this->contentRepository->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->andWhere('c.scheduledDate >= :monthStart')
                ->andWhere('c.scheduledDate <= :monthEnd')
                ->andWhere('c.status IN (:statusIds)')
                ->setParameter('monthStart', $monthStart)
                ->setParameter('monthEnd', $monthEnd)
                ->setParameter('statusIds', $publishedStatusIds)
                ->getQuery()
                ->getSingleScalarResult();
        }

        $overdueQb = $this->contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate < :today')
            ->setParameter('today', $today);
        if ($publishedStatusIds !== []) {
            $overdueQb->andWhere('c.status NOT IN (:statusIds)')
                ->setParameter('statusIds', $publishedStatusIds);
        }
        $overdue = (int) $overdueQb->getQuery()->getSingleScalarResult();

        $upcoming7Days = (int) $this->contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.scheduledDate >= :today')
            ->andWhere('c.scheduledDate <= :endDate')
            ->setParameter('today', $today)
            ->setParameter('endDate', $today->modify('+6 days'))
            ->getQuery()
            ->getSingleScalarResult();

        $completionRate = $postsThisMonth > 0 ? round(($publishedThisMonth / $postsThisMonth) * 100, 1) : 0.0;

        $statusRows = $this->contentRepository->createQueryBuilder('c')
            ->select('s.name AS name, COUNT(c.id) AS total')
            ->join('c.status', 's')
            ->andWhere('c.scheduledDate >= :monthStart')
            ->andWhere('c.scheduledDate <= :monthEnd')
            ->setParameter('monthStart', $monthStart)
            ->setParameter('monthEnd', $monthEnd)
            ->groupBy('s.id')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $clientRows = $this->contentRepository->createQueryBuilder('c')
            ->select('cl.name AS client, COUNT(c.id) AS total')
            ->join('c.client', 'cl')
            ->andWhere('c.scheduledDate >= :monthStart')
            ->andWhere('c.scheduledDate <= :monthEnd')
            ->setParameter('monthStart', $monthStart)
            ->setParameter('monthEnd', $monthEnd)
            ->groupBy('cl.id')
            ->orderBy('total', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getArrayResult();

        $nextPosts = $this->contentRepository->createQueryBuilder('c')
            ->select('c.id, c.title, c.scheduledDate, cl.name AS client, s.name AS status')
            ->join('c.client', 'cl')
            ->join('c.status', 's')
            ->andWhere('c.scheduledDate >= :today')
            ->setParameter('today', $today)
            ->orderBy('c.scheduledDate', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getArrayResult();

        return $this->json([
            'meta' => [
                'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'monthStart' => $monthStart->format('Y-m-d'),
                'monthEnd' => $monthEnd->format('Y-m-d'),
            ],
            'kpi' => [
                'postsThisMonth' => $postsThisMonth,
                'publishedThisMonth' => $publishedThisMonth,
                'completionRate' => $completionRate,
                'overdue' => $overdue,
                'upcoming7Days' => $upcoming7Days,
            ],
            'breakdowns' => [
                'byStatus' => $statusRows,
                'topClients' => $clientRows,
            ],
            'nextPosts' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'scheduledDate' => $row['scheduledDate'] instanceof \DateTimeInterface
                        ? $row['scheduledDate']->format('Y-m-d')
                        : (string) $row['scheduledDate'],
                    'client' => (string) $row['client'],
                    'status' => (string) $row['status'],
                ];
            }, $nextPosts),
        ]);
    }

    private function isAuthorized(Request $request): bool
    {
        $configuredToken = $this->readEnv('AI_API_TOKEN');
        if ($configuredToken === '') {
            return false;
        }

        $bearer = trim((string) preg_replace('/^Bearer\s+/i', '', (string) $request->headers->get('Authorization')));
        $apiKey = trim((string) $request->headers->get('X-API-Key'));
        $providedToken = $bearer !== '' ? $bearer : $apiKey;
        if ($providedToken === '') {
            return false;
        }

        return hash_equals($configuredToken, $providedToken);
    }

    private function isAllowedIp(Request $request): bool
    {
        $raw = $this->readEnv('AI_API_ALLOWED_IPS');
        if ($raw === '') {
            return true;
        }

        $allowedIps = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($allowedIps === []) {
            return true;
        }

        $clientIp = (string) $request->getClientIp();
        return in_array($clientIp, $allowedIps, true);
    }

    private function readEnv(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}

