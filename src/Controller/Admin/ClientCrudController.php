<?php

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Entity\ClientPage;
use App\Entity\CommunityManager;
use App\Entity\Format;
use App\Entity\Status;
use App\Entity\Content;
use App\Entity\User;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/clients')]
class ClientCrudController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_admin_client_index', methods: ['GET'])]
    public function index(): Response
    {
        $clients = $this->entityManager->getRepository(Client::class)
            ->findAllOrderedByClientName();

        return $this->render('admin/client/index.html.twig', [
            'clients' => $clients,
        ]);
    }

    #[Route('/nouveau', name: 'app_admin_client_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $client = new Client();
        if ($request->isMethod('POST')) {
            $cmId = $request->request->getInt('communityManager');
            $cm = $this->entityManager->getRepository(CommunityManager::class)->find($cmId);
            if ($cm) {
                $client->setName($request->request->getString('name'));
                $client->setCommunityManager($cm);
                $editorId = $request->request->getInt('editor');
                $editor = $editorId > 0 ? $this->entityManager->getRepository(User::class)->find($editorId) : null;
                $client->setEditor($editor);
                $this->entityManager->persist($client);
                $this->entityManager->flush();
                $this->addFlash('success', 'Client créé.');
                return $this->redirectToRoute('app_admin_client_index');
            }
        }

        $cms = $this->entityManager->getRepository(CommunityManager::class)
            ->findBy([], ['name' => 'ASC']);
        $editors = $this->entityManager->getRepository(User::class)->findBy([], ['name' => 'ASC']);

        return $this->render('admin/client/form.html.twig', [
            'client' => $client,
            'communityManagers' => $cms,
            'editors' => $editors,
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_admin_client_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Client $client, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $cmId = $request->request->getInt('communityManager');
            $cm = $this->entityManager->getRepository(CommunityManager::class)->find($cmId);
            if ($cm) {
                $client->setName($request->request->getString('name'));
                $client->setCommunityManager($cm);
                $editorId = $request->request->getInt('editor');
                $editor = $editorId > 0 ? $this->entityManager->getRepository(User::class)->find($editorId) : null;
                $client->setEditor($editor);
                $this->entityManager->flush();
                $this->addFlash('success', 'Client modifié.');
                return $this->redirectToRoute('app_admin_client_index');
            }
        }

        $cms = $this->entityManager->getRepository(CommunityManager::class)
            ->findBy([], ['name' => 'ASC']);
        $editors = $this->entityManager->getRepository(User::class)->findBy([], ['name' => 'ASC']);

        return $this->render('admin/client/form.html.twig', [
            'client' => $client,
            'communityManagers' => $cms,
            'editors' => $editors,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'app_admin_client_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Client $client, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_client_'.$client->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_client_index');
        }

        $usage = $this->entityManager->getRepository(Content::class)->count(['client' => $client]);
        if ($usage > 0) {
            $this->addFlash('error', sprintf(
                'Impossible de supprimer « %s » : %d publication(s) y sont encore liées. Utilisez « Fusionner » pour tout rattacher à l’autre fiche client, puis supprimez ce doublon.',
                $client->getName() ?? '',
                $usage
            ));

            return $this->redirectToRoute('app_admin_client_index');
        }

        try {
            $this->entityManager->remove($client);
            $this->entityManager->flush();
            $this->addFlash('success', 'Client supprimé.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Suppression impossible (contrainte base de données). Détail : '.$e->getMessage());
        }

        return $this->redirectToRoute('app_admin_client_index');
    }

    #[Route('/{id}/fusionner', name: 'app_admin_client_merge', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function merge(Client $client, Request $request, ClientRepository $clientRepository): Response
    {
        $all = $clientRepository->findAllOrderedByClientName();
        $targets = array_values(array_filter($all, static fn (Client $c) => $c->getId() !== $client->getId()));
        if ($targets === []) {
            $this->addFlash('error', 'Il faut au moins deux clients pour fusionner.');

            return $this->redirectToRoute('app_admin_client_index');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('merge_client_'.$client->getId(), $request->request->getString('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');

                return $this->redirectToRoute('app_admin_client_merge', ['id' => $client->getId()]);
            }

            $targetId = $request->request->getInt('target_client_id');
            $target = $clientRepository->find($targetId);
            if (!$target instanceof Client || $target->getId() === $client->getId()) {
                $this->addFlash('error', 'Client cible invalide.');

                return $this->redirectToRoute('app_admin_client_merge', ['id' => $client->getId()]);
            }

            $conn = $this->entityManager->getConnection();
            $conn->beginTransaction();
            try {
                $this->mergeClientInto($client, $target);
                $this->entityManager->remove($client);
                $this->entityManager->flush();
                $conn->commit();
                $this->addFlash('success', sprintf(
                    'Le client « %s » a été fusionné dans « %s ». Vous pouvez vérifier la fiche cible puis supprimer d’éventuels doublons restants si besoin.',
                    $client->getName() ?? '',
                    $target->getName() ?? ''
                ));
            } catch (\Throwable $e) {
                $conn->rollBack();
                $this->entityManager->clear();
                $this->addFlash('error', 'La fusion a échoué : '.$e->getMessage());

                return $this->redirectToRoute('app_admin_client_merge', ['id' => $client->getId()]);
            }

            return $this->redirectToRoute('app_admin_client_index');
        }

        $contentCount = $this->entityManager->getRepository(Content::class)->count(['client' => $client]);

        return $this->render('admin/client/merge.html.twig', [
            'source' => $client,
            'targets' => $targets,
            'contentCount' => $contentCount,
        ]);
    }

    private function mergeClientInto(Client $source, Client $target): void
    {
        foreach ($source->getContents()->toArray() as $content) {
            $content->setClient($target);
        }

        if ($target->getEditor() === null && $source->getEditor() !== null) {
            $target->setEditor($source->getEditor());
        }

        $sourcePage = $source->getClientPage();
        if ($sourcePage instanceof ClientPage) {
            $targetPage = $target->getClientPage();
            if (!$targetPage instanceof ClientPage) {
                $source->setClientPage(null);
                $sourcePage->setClient($target);
                $target->setClientPage($sourcePage);
            } else {
                $srcInfo = trim((string) $sourcePage->getImportantInfo());
                $srcIdeas = trim((string) $sourcePage->getIdeas());
                if ($srcInfo !== '') {
                    $cur = trim((string) $targetPage->getImportantInfo());
                    $block = "\n\n---\nFusion depuis « ".$source->getName()." »\n".$srcInfo;
                    $targetPage->setImportantInfo($cur === '' ? ltrim($block) : $cur.$block);
                }
                if ($srcIdeas !== '') {
                    $cur = trim((string) $targetPage->getIdeas());
                    $block = "\n\n---\nFusion depuis « ".$source->getName()." »\n".$srcIdeas;
                    $targetPage->setIdeas($cur === '' ? ltrim($block) : $cur.$block);
                }
                $nextOrder = 0;
                foreach ($targetPage->getTodoItems() as $t) {
                    $nextOrder = max($nextOrder, $t->getSortOrder() ?? 0);
                }
                foreach ($sourcePage->getTodoItems()->toArray() as $todo) {
                    $sourcePage->getTodoItems()->removeElement($todo);
                    ++$nextOrder;
                    $todo->setSortOrder($nextOrder);
                    $targetPage->addTodoItem($todo);
                }
                $source->setClientPage(null);
                $this->entityManager->remove($sourcePage);
            }
        }
    }

    #[Route('/statuts-formats', name: 'app_admin_status_format_index', methods: ['GET'])]
    public function statusFormatIndex(): Response
    {
        $statuses = $this->entityManager->getRepository(Status::class)
            ->findAllOrdered();
        $formats = $this->entityManager->getRepository(Format::class)
            ->findAllOrdered();

        $contentRepository = $this->entityManager->getRepository(Content::class);
        $statusUsage = [];
        foreach ($statuses as $status) {
            $statusUsage[$status->getId()] = $contentRepository->count(['status' => $status]);
        }

        $formatUsage = [];
        foreach ($formats as $format) {
            $formatUsage[$format->getId()] = $contentRepository->count(['format' => $format]);
        }

        return $this->render('admin/client/status_format.html.twig', [
            'statuses' => $statuses,
            'formats' => $formats,
            'statusUsage' => $statusUsage,
            'formatUsage' => $formatUsage,
            'statusColors' => [
                'gray' => 'Gris',
                'taupe' => 'Taupe',
                'canard' => 'Canard',
                'violet' => 'Violet',
                'bleu-nuit' => 'Bleu nuit',
                'ardoise' => 'Ardoise',
                'green' => 'Vert',
                'lightgreen' => 'Vert clair',
                'sauge' => 'Sauge',
                'menthe' => 'Menthe',
                'yellow' => 'Jaune',
                'moutarde' => 'Moutarde',
                'orange' => 'Orange',
                'corail' => 'Corail',
                'red' => 'Rouge',
                'framboise' => 'Framboise',
                'rose-poudre' => 'Rose poudre',
                'fuchsia' => 'Fuchsia',
            ],
            'statusColorHexes' => [
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
            ],
        ]);
    }

    #[Route('/statuts-formats/status/ajouter', name: 'app_admin_status_add', methods: ['POST'])]
    public function addStatus(Request $request): Response
    {
        $name = trim($request->request->getString('name'));
        $color = trim($request->request->getString('color'));

        if ($name === '' || $color === '') {
            $this->addFlash('error', 'Nom et couleur du statut sont obligatoires.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $status = new Status();
        $status->setName($name);
        $status->setColor($color);
        $status->setSortOrder($this->getNextStatusSortOrder());

        $this->entityManager->persist($status);
        $this->entityManager->flush();
        $this->addFlash('success', 'Statut ajoute.');

        return $this->redirectToRoute('app_admin_status_format_index');
    }

    #[Route('/statuts-formats/status/{id}/modifier', name: 'app_admin_status_edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function editStatus(Status $status, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('edit_status_'.$status->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $name = trim($request->request->getString('name'));
        $color = trim($request->request->getString('color'));

        if ($name === '' || $color === '') {
            $this->addFlash('error', 'Nom et couleur du statut sont obligatoires.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $status->setName($name);
        $status->setColor($color);
        $this->entityManager->flush();
        $this->addFlash('success', 'Statut modifie.');

        return $this->redirectToRoute('app_admin_status_format_index');
    }

    #[Route('/statuts-formats/status/{id}/ordre/{direction}', name: 'app_admin_status_move', requirements: ['id' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function moveStatus(Status $status, string $direction, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('move_status_'.$status->getId().'_'.$direction, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $ordered = $this->entityManager->getRepository(Status::class)->findAllOrdered();
        $this->moveItemInOrderedList($ordered, $status->getId(), $direction);

        return $this->redirectToRoute('app_admin_status_format_index');
    }

    #[Route('/statuts-formats/status/{id}/supprimer', name: 'app_admin_status_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteStatus(Status $status, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_status_'.$status->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $usage = $this->entityManager->getRepository(Content::class)->count(['status' => $status]);
        if ($usage > 0) {
            $this->addFlash('error', 'Impossible de supprimer ce statut: il est utilise par des posts.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $this->entityManager->remove($status);
        $this->entityManager->flush();
        $this->addFlash('success', 'Statut supprime.');

        return $this->redirectToRoute('app_admin_status_format_index');
    }

    #[Route('/statuts-formats/format/ajouter', name: 'app_admin_format_add', methods: ['POST'])]
    public function addFormat(Request $request): Response
    {
        $name = trim($request->request->getString('name'));

        if ($name === '') {
            $this->addFlash('error', 'Le nom du format est obligatoire.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $format = new Format();
        $format->setName($name);
        $format->setSortOrder($this->getNextFormatSortOrder());

        $this->entityManager->persist($format);
        $this->entityManager->flush();
        $this->addFlash('success', 'Format ajoute.');

        return $this->redirectToRoute('app_admin_status_format_index');
    }

    #[Route('/statuts-formats/format/{id}/modifier', name: 'app_admin_format_edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function editFormat(Format $format, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('edit_format_'.$format->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $name = trim($request->request->getString('name'));

        if ($name === '') {
            $this->addFlash('error', 'Le nom du format est obligatoire.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $format->setName($name);
        $this->entityManager->flush();
        $this->addFlash('success', 'Format modifie.');

        return $this->redirectToRoute('app_admin_status_format_index');
    }

    #[Route('/statuts-formats/format/{id}/ordre/{direction}', name: 'app_admin_format_move', requirements: ['id' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function moveFormat(Format $format, string $direction, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('move_format_'.$format->getId().'_'.$direction, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $ordered = $this->entityManager->getRepository(Format::class)->findAllOrdered();
        $this->moveItemInOrderedList($ordered, $format->getId(), $direction);

        return $this->redirectToRoute('app_admin_status_format_index');
    }

    #[Route('/statuts-formats/format/{id}/supprimer', name: 'app_admin_format_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteFormat(Format $format, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_format_'.$format->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $usage = $this->entityManager->getRepository(Content::class)->count(['format' => $format]);
        if ($usage > 0) {
            $this->addFlash('error', 'Impossible de supprimer ce format: il est utilise par des posts.');
            return $this->redirectToRoute('app_admin_status_format_index');
        }

        $this->entityManager->remove($format);
        $this->entityManager->flush();
        $this->addFlash('success', 'Format supprime.');

        return $this->redirectToRoute('app_admin_status_format_index');
    }

    private function getNextStatusSortOrder(): int
    {
        $statuses = $this->entityManager->getRepository(Status::class)->findAllOrdered();
        $max = 0;
        foreach ($statuses as $status) {
            $value = $status->getSortOrder() ?? 0;
            if ($value > $max) {
                $max = $value;
            }
        }

        return $max + 1;
    }

    private function getNextFormatSortOrder(): int
    {
        $formats = $this->entityManager->getRepository(Format::class)->findAllOrdered();
        $max = 0;
        foreach ($formats as $format) {
            $value = $format->getSortOrder() ?? 0;
            if ($value > $max) {
                $max = $value;
            }
        }

        return $max + 1;
    }

    /**
     * @param array<int, object> $items
     */
    private function moveItemInOrderedList(array $items, int $itemId, string $direction): void
    {
        $index = null;
        foreach ($items as $i => $item) {
            if (method_exists($item, 'getId') && $item->getId() === $itemId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($items[$targetIndex])) {
            return;
        }

        $current = $items[$index];
        $target = $items[$targetIndex];

        if (!method_exists($current, 'getSortOrder') || !method_exists($target, 'getSortOrder')) {
            return;
        }

        $currentOrder = $current->getSortOrder() ?? 0;
        $targetOrder = $target->getSortOrder() ?? 0;

        if (method_exists($current, 'setSortOrder') && method_exists($target, 'setSortOrder')) {
            $current->setSortOrder($targetOrder);
            $target->setSortOrder($currentOrder);
            $this->entityManager->flush();
        }
    }
}
