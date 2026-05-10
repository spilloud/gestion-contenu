<?php

namespace App\Controller\Api;

use App\Entity\Client;
use App\Entity\ClientPage;
use App\Entity\CommunityManager;
use App\Entity\Content;
use App\Entity\ContentComment;
use App\Entity\Format;
use App\Entity\Status;
use App\Entity\TodoItem;
use App\Entity\User;
use App\Repository\ClientPageRepository;
use App\Repository\ClientRepository;
use App\Repository\CommunityManagerRepository;
use App\Repository\ContentRepository;
use App\Repository\FormatRepository;
use App\Repository\StatusRepository;
use App\Repository\UserRepository;
use App\Service\AiApiAccessChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Export métier complet pour Lucy / n8n (hors secrets : pas de mots de passe ni jetons de réinitialisation).
 */
#[Route('/api/ai')]
class AiFullExportController extends AbstractController
{
    public function __construct(
        private readonly AiApiAccessChecker $aiApiAccessChecker,
        private readonly UserRepository $userRepository,
        private readonly ClientRepository $clientRepository,
        private readonly CommunityManagerRepository $communityManagerRepository,
        private readonly FormatRepository $formatRepository,
        private readonly StatusRepository $statusRepository,
        private readonly ContentRepository $contentRepository,
        private readonly ClientPageRepository $clientPageRepository,
    ) {
    }

    #[Route('/full-export', name: 'app_api_ai_full_export', methods: ['GET'])]
    public function fullExport(Request $request): JsonResponse
    {
        if ($response = $this->aiApiAccessChecker->validate($request)) {
            return $response;
        }

        $includeContents = filter_var($request->query->get('include_contents', '1'), FILTER_VALIDATE_BOOLEAN);
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(500, max(50, (int) $request->query->get('per_page', 200)));

        $formats = $this->formatRepository->findAllOrdered();
        $statuses = $this->statusRepository->findAllOrdered();
        $communityManagers = $this->communityManagerRepository->findAllOrderedByName();
        $clients = $this->clientRepository->findAllOrderedByClientNameIncludingArchived();

        $users = $this->userRepository->createQueryBuilder('u')
            ->orderBy('u.name', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();

        $clientPages = $this->clientPageRepository->createQueryBuilder('cp')
            ->leftJoin('cp.client', 'cl')->addSelect('cl')
            ->leftJoin('cp.todoItems', 'ti')->addSelect('ti')
            ->orderBy('cl.name', 'ASC')
            ->getQuery()
            ->getResult();

        $contentsTotal = (int) $this->contentRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = $contentsTotal > 0 ? (int) ceil($contentsTotal / $perPage) : 0;
        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
        }

        $contentsPayload = [];
        if ($includeContents && $contentsTotal > 0) {
            $offset = ($page - 1) * $perPage;
            $qb = $this->contentRepository->createQueryBuilder('c')
                ->leftJoin('c.client', 'cl')->addSelect('cl')
                ->leftJoin('cl.communityManager', 'clcm')->addSelect('clcm')
                ->leftJoin('cl.editor', 'cle')->addSelect('cle')
                ->leftJoin('c.format', 'f')->addSelect('f')
                ->leftJoin('c.status', 's')->addSelect('s')
                ->leftJoin('c.videoEditor', 've')->addSelect('ve')
                ->leftJoin('c.comments', 'cc')->addSelect('cc')
                ->leftJoin('cc.author', 'cca')->addSelect('cca')
                ->orderBy('c.scheduledDate', 'DESC')
                ->addOrderBy('c.id', 'DESC')
                ->setFirstResult($offset)
                ->setMaxResults($perPage);

            /** @var Content[] $rows */
            $rows = $qb->getQuery()->getResult();
            foreach ($rows as $content) {
                $contentsPayload[] = $this->serializeContent($content);
            }
        }

        return $this->json([
            'meta' => [
                'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'includeContents' => $includeContents,
                'contentsPage' => $includeContents ? $page : null,
                'contentsPerPage' => $includeContents ? $perPage : null,
                'contentsTotal' => $contentsTotal,
                'contentsTotalPages' => $includeContents ? $totalPages : null,
            ],
            'reference' => [
                'formats' => array_map(fn (Format $f) => $this->serializeFormat($f), $formats),
                'statuses' => array_map(fn (Status $s) => $this->serializeStatus($s), $statuses),
                'communityManagers' => array_map(fn (CommunityManager $cm) => $this->serializeCommunityManager($cm), $communityManagers),
                'users' => array_map(fn (User $u) => $this->serializeUser($u), $users),
                'clients' => array_map(fn (Client $cl) => $this->serializeClient($cl), $clients),
                'clientPages' => array_map(fn (ClientPage $cp) => $this->serializeClientPage($cp), $clientPages),
            ],
            'contents' => $contentsPayload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $u): array
    {
        return [
            'id' => $u->getId(),
            'name' => $u->getName(),
            'email' => $u->getEmail(),
            'role' => $u->getRole(),
            'roles' => $u->getRoles(),
            'asanaUserGid' => $u->getAsanaUserGid(),
            'createdAt' => $u->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userSnapshot(?User $u): ?array
    {
        return $u !== null ? $this->serializeUser($u) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCommunityManager(CommunityManager $cm): array
    {
        return [
            'id' => $cm->getId(),
            'name' => $cm->getName(),
            'email' => $cm->getEmail(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClient(Client $c): array
    {
        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'asanaProjectGid' => $c->getAsanaProjectGid(),
            'isArchived' => $c->isArchived(),
            'communityManager' => $c->getCommunityManager() !== null
                ? $this->serializeCommunityManager($c->getCommunityManager())
                : null,
            'editor' => $this->userSnapshot($c->getEditor()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFormat(Format $f): array
    {
        return [
            'id' => $f->getId(),
            'name' => $f->getName(),
            'sortOrder' => $f->getSortOrder(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeStatus(Status $s): array
    {
        return [
            'id' => $s->getId(),
            'name' => $s->getName(),
            'color' => $s->getColor(),
            'sortOrder' => $s->getSortOrder(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClientPage(ClientPage $cp): array
    {
        $client = $cp->getClient();
        $todos = [];
        foreach ($cp->getTodoItems() as $item) {
            if ($item instanceof TodoItem) {
                $todos[] = $this->serializeTodoItem($item);
            }
        }

        return [
            'id' => $cp->getId(),
            'clientId' => $client?->getId(),
            'clientName' => $client?->getName(),
            'importantInfo' => $cp->getImportantInfo(),
            'ideas' => $cp->getIdeas(),
            'updatedAt' => $cp->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'todoItems' => $todos,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTodoItem(TodoItem $t): array
    {
        return [
            'id' => $t->getId(),
            'label' => $t->getLabel(),
            'done' => $t->isDone(),
            'sortOrder' => $t->getSortOrder(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeComment(ContentComment $cc): array
    {
        return [
            'id' => $cc->getId(),
            'message' => $cc->getMessage(),
            'createdAt' => $cc->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'author' => $this->userSnapshot($cc->getAuthor()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeContent(Content $c): array
    {
        $scheduled = $c->getScheduledDate();
        $comments = [];
        foreach ($c->getComments() as $cc) {
            $comments[] = $this->serializeComment($cc);
        }

        return [
            'id' => $c->getId(),
            'title' => $c->getTitle(),
            'scheduledDate' => $scheduled !== null ? $scheduled->format('Y-m-d') : null,
            'notes' => $c->getNotes(),
            'client' => $c->getClient() !== null ? $this->serializeClient($c->getClient()) : null,
            'format' => $c->getFormat() !== null ? $this->serializeFormat($c->getFormat()) : null,
            'status' => $c->getStatus() !== null ? $this->serializeStatus($c->getStatus()) : null,
            'video' => [
                'hasSubtitles' => $c->getVideoHasSubtitles(),
                'editor' => $this->userSnapshot($c->getVideoEditor()),
                'rushesUrl' => $c->getVideoRushesUrl(),
                'editUrl' => $c->getVideoEditUrl(),
                'editFilename' => $c->getVideoEditFilename(),
                'submagicUrl' => $c->getVideoSubmagicUrl(),
                'finalUrl' => $c->getVideoFinalUrl(),
                'finalFilename' => $c->getVideoFinalFilename(),
                'thumbnailUrl' => $c->getVideoThumbnailUrl(),
                'caption' => $c->getVideoCaption(),
            ],
            'asana' => [
                'taskGid' => $c->getAsanaTaskGid(),
                'subtitlesTaskGid' => $c->getAsanaSubtitlesTaskGid(),
            ],
            'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $c->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'comments' => $comments,
        ];
    }
}
