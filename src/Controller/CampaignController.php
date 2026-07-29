<?php

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\CampaignCategory;
use App\Entity\Client;
use App\Entity\Content;
use App\Form\CampaignCategoryType;
use App\Form\CampaignType;
use App\Repository\CampaignCategoryRepository;
use App\Repository\CampaignRepository;
use App\Repository\ContentRepository;
use App\Service\ContentFormatHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/client/{id}/campagne', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
class CampaignController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CampaignRepository $campaignRepository,
        private readonly CampaignCategoryRepository $campaignCategoryRepository,
        private readonly ContentRepository $contentRepository,
        private readonly ContentFormatHelper $contentFormatHelper,
    ) {
    }

    #[Route('', name: 'app_client_campaign', methods: ['GET'])]
    public function show(Client $client, Request $request): Response
    {
        $campaign = $this->resolveCampaign($client, $request);
        $month = $request->query->getInt('cmonth', (int) date('n'));
        $year = $request->query->getInt('cyear', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > (int) date('Y') + 5) {
            $year = (int) date('Y');
        }

        $monthStart = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $monthEnd = $monthStart->modify('last day of this month');
        $gridStart = $monthStart->modify('monday this week');
        $gridEnd = $gridStart->modify('+41 days');

        $categories = [];
        $contents = [];
        $weeks = [];
        $trayItems = [];

        if ($campaign !== null) {
            $categories = $this->campaignCategoryRepository->findOrderedForCampaign($campaign);
            $rangeStart = $campaign->getStartsOn() !== null && $campaign->getStartsOn() > $gridStart
                ? $campaign->getStartsOn()
                : $gridStart;
            $rangeEnd = $campaign->getEndsOn() !== null && $campaign->getEndsOn() < $gridEnd
                ? $campaign->getEndsOn()
                : $gridEnd;
            if ($rangeStart > $rangeEnd) {
                $contents = [];
            } else {
                $contents = $this->contentRepository->findForCampaignGrid($client, $rangeStart, $rangeEnd);
            }
            $weeks = $this->buildWeeks($gridStart, $categories, $contents, $campaign, $monthStart);

            $today = new \DateTimeImmutable('today');
            $trayFrom = $today;
            $trayUntil = $campaign->getEndsOn();
            $trayContents = ($trayUntil !== null && $trayUntil < $trayFrom)
                ? []
                : $this->contentRepository->findUnclassifiedFromDateForClient(
                    $client,
                    $campaign,
                    $trayFrom,
                    $trayUntil,
                );
            foreach ($trayContents as $content) {
                $isVideo = $this->contentFormatHelper->isVideoContent($content);
                $trayItems[] = [
                    'content' => $content,
                    'isVideo' => $isVideo,
                    'editUrl' => $isVideo
                        ? $this->generateUrl('app_video_show', ['id' => $content->getId()])
                        : $this->generateUrl('app_content_edit', ['id' => $content->getId()]),
                ];
            }
        }

        $emptyCampaign = new Campaign();
        if ($campaign === null) {
            $emptyCampaign->setStartsOn(new \DateTimeImmutable('first day of this month'));
            $emptyCampaign->setEndsOn(new \DateTimeImmutable('last day of this month'));
        }

        $campaignForm = $this->createForm(CampaignType::class, $campaign ?? $emptyCampaign);
        $categoryForm = $this->createForm(CampaignCategoryType::class, (new CampaignCategory())->setColor(CampaignCategory::DEFAULT_COLOR));

        return $this->render('campaign/show.html.twig', [
            'client' => $client,
            'campaign' => $campaign,
            'categories' => $categories,
            'weeks' => $weeks,
            'trayItems' => $trayItems,
            'calendarMonth' => $month,
            'calendarYear' => $year,
            'calendarMonthStart' => $monthStart,
            'campaignForm' => $campaignForm->createView(),
            'categoryForm' => $categoryForm->createView(),
            'canManage' => $this->canManageCampaign(),
        ]);
    }

    #[Route('/creer', name: 'app_client_campaign_create', methods: ['POST'])]
    public function create(Client $client, Request $request): Response
    {
        $this->denyUnlessCanManage();

        $campaign = new Campaign();
        $campaign->setClient($client);
        $form = $this->createForm(CampaignType::class, $campaign);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Campagne invalide — vérifiez le nom et les dates.');

            return $this->redirectToRoute('app_client_campaign', ['id' => $client->getId()]);
        }

        if ($this->campaignRepository->findOverlapping($client, $campaign->getStartsOn(), $campaign->getEndsOn()) !== []) {
            $this->addFlash('error', 'Une campagne existe déjà sur cette période (une seule campagne à la fois par client).');

            return $this->redirectToRoute('app_client_campaign', ['id' => $client->getId()]);
        }

        $this->entityManager->persist($campaign);
        $this->entityManager->flush();
        $this->addFlash('success', 'Campagne créée. Ajoutez des catégories pour organiser le planning.');

        return $this->redirectToRoute('app_client_campaign', [
            'id' => $client->getId(),
            'cmonth' => (int) $campaign->getStartsOn()->format('n'),
            'cyear' => (int) $campaign->getStartsOn()->format('Y'),
        ]);
    }

    #[Route('/{campaignId}/modifier', name: 'app_client_campaign_edit', requirements: ['campaignId' => '\d+'], methods: ['POST'])]
    public function edit(Client $client, int $campaignId, Request $request): Response
    {
        $this->denyUnlessCanManage();
        $campaign = $this->getCampaignForClient($client, $campaignId);

        $form = $this->createForm(CampaignType::class, $campaign);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Modification invalide — vérifiez le nom et les dates.');

            return $this->redirectToRoute('app_client_campaign', ['id' => $client->getId()]);
        }

        if ($this->campaignRepository->findOverlapping($client, $campaign->getStartsOn(), $campaign->getEndsOn(), $campaign) !== []) {
            $this->addFlash('error', 'Les dates chevauchent une autre campagne de ce client.');

            return $this->redirectToRoute('app_client_campaign', ['id' => $client->getId()]);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'Campagne mise à jour.');

        return $this->redirectToRoute('app_client_campaign', [
            'id' => $client->getId(),
            'cmonth' => $request->query->getInt('cmonth', (int) date('n')),
            'cyear' => $request->query->getInt('cyear', (int) date('Y')),
        ]);
    }

    #[Route('/{campaignId}/supprimer', name: 'app_client_campaign_delete', requirements: ['campaignId' => '\d+'], methods: ['POST'])]
    public function delete(Client $client, int $campaignId, Request $request): Response
    {
        $this->denyUnlessCanManage();
        $campaign = $this->getCampaignForClient($client, $campaignId);

        if (!$this->isCsrfTokenValid('campaign_delete'.$campaign->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_client_campaign', ['id' => $client->getId()]);
        }

        $this->entityManager->remove($campaign);
        $this->entityManager->flush();
        $this->addFlash('success', 'Campagne supprimée.');

        return $this->redirectToRoute('app_client_show', ['id' => $client->getId()]);
    }

    #[Route('/{campaignId}/categories', name: 'app_client_campaign_category_create', requirements: ['campaignId' => '\d+'], methods: ['POST'])]
    public function createCategory(Client $client, int $campaignId, Request $request): Response
    {
        $this->denyUnlessCanManage();
        $campaign = $this->getCampaignForClient($client, $campaignId);

        $category = new CampaignCategory();
        $category->setCampaign($campaign);
        $category->setSortOrder($this->campaignCategoryRepository->nextSortOrder($campaign));
        $category->setColor(CampaignCategory::DEFAULT_COLOR);
        $form = $this->createForm(CampaignCategoryType::class, $category);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Catégorie invalide.');

            return $this->redirectToCampaignView($client, $request);
        }

        if ($category->getSortOrder() < 1) {
            $category->setSortOrder($this->campaignCategoryRepository->nextSortOrder($campaign));
        }

        $this->entityManager->persist($category);
        $this->entityManager->flush();
        $this->addFlash('success', 'Catégorie ajoutée.');

        return $this->redirectToCampaignView($client, $request);
    }

    #[Route('/{campaignId}/categories/{categoryId}/modifier', name: 'app_client_campaign_category_edit', requirements: ['campaignId' => '\d+', 'categoryId' => '\d+'], methods: ['POST'])]
    public function editCategory(Client $client, int $campaignId, int $categoryId, Request $request): Response
    {
        $this->denyUnlessCanManage();
        $campaign = $this->getCampaignForClient($client, $campaignId);
        $category = $this->getCategoryForCampaign($campaign, $categoryId);

        if (!$this->isCsrfTokenValid('category_edit'.$category->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToCampaignView($client, $request);
        }

        $name = trim($request->request->getString('name'));
        $color = trim($request->request->getString('color'));
        $sortOrder = $request->request->getInt('sortOrder', $category->getSortOrder());

        if ($name === '') {
            $this->addFlash('error', 'Le nom de catégorie est obligatoire.');

            return $this->redirectToCampaignView($client, $request);
        }

        $category->setName($name);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $category->setColor($color);
        }
        $category->setSortOrder($sortOrder);
        $this->entityManager->flush();
        $this->addFlash('success', 'Catégorie mise à jour.');

        return $this->redirectToCampaignView($client, $request);
    }

    #[Route('/{campaignId}/categories/{categoryId}/supprimer', name: 'app_client_campaign_category_delete', requirements: ['campaignId' => '\d+', 'categoryId' => '\d+'], methods: ['POST'])]
    public function deleteCategory(Client $client, int $campaignId, int $categoryId, Request $request): Response
    {
        $this->denyUnlessCanManage();
        $campaign = $this->getCampaignForClient($client, $campaignId);
        $category = $this->getCategoryForCampaign($campaign, $categoryId);

        if (!$this->isCsrfTokenValid('category_delete'.$category->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToCampaignView($client, $request);
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();
        $this->addFlash('success', 'Catégorie supprimée (posts concernés : Non classé).');

        return $this->redirectToCampaignView($client, $request);
    }

    #[Route('/contenu/{contentId}/deplacer', name: 'app_client_campaign_move', requirements: ['contentId' => '\d+'], methods: ['POST'])]
    public function move(Client $client, int $contentId, Request $request): JsonResponse
    {
        $this->denyUnlessCanManage();

        $content = $this->contentRepository->find($contentId);
        if (!$content instanceof Content || $content->getClient()?->getId() !== $client->getId()) {
            return new JsonResponse(['ok' => false, 'error' => 'Contenu introuvable.'], 404);
        }

        if (!$this->isCsrfTokenValid('campaign_move'.$content->getId(), $request->request->getString('_token'))
            && !$this->isCsrfTokenValid('campaign_move', $request->request->getString('_token'))
        ) {
            return new JsonResponse(['ok' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $dateStr = $request->request->getString('date');
        if ($dateStr === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Date manquante.'], 400);
        }

        try {
            $content->setScheduledDate(new \DateTime($dateStr));
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false, 'error' => 'Date invalide.'], 400);
        }

        $categoryRaw = $request->request->get('categoryId');
        if ($categoryRaw === null || $categoryRaw === '' || (int) $categoryRaw === 0) {
            $content->setCampaignCategory(null);
        } else {
            $category = $this->campaignCategoryRepository->find((int) $categoryRaw);
            if (!$category instanceof CampaignCategory
                || $category->getCampaign()?->getClient()?->getId() !== $client->getId()
            ) {
                return new JsonResponse(['ok' => false, 'error' => 'Catégorie invalide.'], 400);
            }
            $content->setCampaignCategory($category);
        }

        $content->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return new JsonResponse([
            'ok' => true,
            'date' => $content->getScheduledDate()?->format('Y-m-d'),
            'categoryId' => $content->getCampaignCategory()?->getId(),
        ]);
    }

    private function resolveCampaign(Client $client, Request $request): ?Campaign
    {
        $campaignId = $request->query->getInt('campaign');
        if ($campaignId > 0) {
            $campaign = $this->campaignRepository->find($campaignId);
            if ($campaign instanceof Campaign && $campaign->getClient()?->getId() === $client->getId()) {
                return $campaign;
            }
        }

        return $this->campaignRepository->findPreferredForClient($client);
    }

    private function getCampaignForClient(Client $client, int $campaignId): Campaign
    {
        $campaign = $this->campaignRepository->find($campaignId);
        if (!$campaign instanceof Campaign || $campaign->getClient()?->getId() !== $client->getId()) {
            throw $this->createNotFoundException('Campagne introuvable.');
        }

        return $campaign;
    }

    private function getCategoryForCampaign(Campaign $campaign, int $categoryId): CampaignCategory
    {
        $category = $this->campaignCategoryRepository->find($categoryId);
        if (!$category instanceof CampaignCategory || $category->getCampaign()?->getId() !== $campaign->getId()) {
            throw $this->createNotFoundException('Catégorie introuvable.');
        }

        return $category;
    }

    private function redirectToCampaignView(Client $client, Request $request): Response
    {
        return $this->redirectToRoute('app_client_campaign', [
            'id' => $client->getId(),
            'cmonth' => $request->query->getInt('cmonth', $request->request->getInt('cmonth', (int) date('n'))),
            'cyear' => $request->query->getInt('cyear', $request->request->getInt('cyear', (int) date('Y'))),
        ]);
    }

    private function canManageCampaign(): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_CM');
    }

    private function denyUnlessCanManage(): void
    {
        if (!$this->canManageCampaign()) {
            throw $this->createAccessDeniedException('Seuls les admin et CM peuvent gérer les campagnes.');
        }
    }

    /**
     * @param CampaignCategory[] $categories
     * @param Content[]          $contents
     *
     * @return list<array{start: \DateTimeImmutable, days: list<\DateTimeImmutable>, rows: list<array{category: ?CampaignCategory, label: string, color: string, cells: array<string, list<array{content: Content, isVideo: bool, editUrl: string}>}>}>
     */
    private function buildWeeks(
        \DateTimeImmutable $gridStart,
        array $categories,
        array $contents,
        Campaign $campaign,
        \DateTimeImmutable $monthStart,
    ): array {
        $byKey = [];
        foreach ($contents as $content) {
            $date = $content->getScheduledDate();
            if ($date === null || !$campaign->containsDate($date)) {
                continue;
            }
            $dayKey = $date->format('Y-m-d');
            $catId = $content->getCampaignCategory()?->getId() ?? 0;
            // Ignore category from another campaign
            $cat = $content->getCampaignCategory();
            if ($cat !== null && $cat->getCampaign()?->getId() !== $campaign->getId()) {
                $catId = 0;
            }
            $isVideo = $this->contentFormatHelper->isVideoContent($content);
            $editUrl = $isVideo
                ? $this->generateUrl('app_video_show', ['id' => $content->getId()])
                : $this->generateUrl('app_content_edit', ['id' => $content->getId()]);
            $byKey[$catId][$dayKey][] = [
                'content' => $content,
                'isVideo' => $isVideo,
                'editUrl' => $editUrl,
            ];
        }

        $rowDefs = [];
        foreach ($categories as $category) {
            $rowDefs[] = [
                'category' => $category,
                'label' => $category->getName() ?? '—',
                'color' => $category->getColor(),
            ];
        }
        $rowDefs[] = [
            'category' => null,
            'label' => 'Non classé',
            'color' => '#f1f5f9',
        ];

        $weeks = [];
        for ($w = 0; $w < 6; ++$w) {
            $weekStart = $gridStart->modify('+'.($w * 7).' days');
            $days = [];
            for ($d = 0; $d < 7; ++$d) {
                $days[] = $weekStart->modify('+'.$d.' days');
            }

            // Skip week entirely outside the displayed month (optional: keep all 6 weeks)
            $inMonth = false;
            foreach ($days as $day) {
                if ($day->format('Y-m') === $monthStart->format('Y-m')) {
                    $inMonth = true;
                    break;
                }
            }
            if (!$inMonth) {
                continue;
            }

            $rows = [];
            foreach ($rowDefs as $def) {
                $catId = $def['category']?->getId() ?? 0;
                $cells = [];
                foreach ($days as $day) {
                    $dayKey = $day->format('Y-m-d');
                    $cells[$dayKey] = $byKey[$catId][$dayKey] ?? [];
                }
                $rows[] = [
                    'category' => $def['category'],
                    'label' => $def['label'],
                    'color' => $def['color'],
                    'cells' => $cells,
                ];
            }

            $weeks[] = [
                'start' => $weekStart,
                'days' => $days,
                'rows' => $rows,
            ];
        }

        return $weeks;
    }
}
