<?php

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\ShootingRequestRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users')]
class UserCrudController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClientRepository $clientRepository,
        private readonly ShootingRequestRepository $shootingRequestRepository,
    ) {
    }

    #[Route('', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(): Response
    {
        $users = $this->entityManager->getRepository(User::class)->findBy([], ['name' => 'ASC']);

        $clientUsers = [];
        $osmoseUsers = [];
        foreach ($users as $u) {
            if ($u instanceof User && $u->isClientAccount()) {
                $clientUsers[] = $u;
            } else {
                $osmoseUsers[] = $u;
            }
        }

        return $this->render('admin/user/index.html.twig', [
            'osmoseUsers' => $osmoseUsers,
            'clientUsers' => $clientUsers,
            'deletionBlockers' => $this->deletionBlockersByUserId($users),
        ]);
    }

    #[Route('/nouveau', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();

        if ($request->isMethod('POST')) {
            $name = trim($request->request->getString('name'));
            $email = trim($request->request->getString('email'));
            $password = (string) $request->request->getString('password');

            if ($name === '' || $email === '' || $password === '') {
                $this->addFlash('error', 'Nom, email et mot de passe sont obligatoires.');

                return $this->render('admin/user/form.html.twig', [
                    'user' => $user,
                ]);
            }

            $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existing) {
                $this->addFlash('error', 'Un utilisateur existe déjà avec cet email.');

                return $this->render('admin/user/form.html.twig', [
                    'user' => $user,
                ]);
            }

            $roles = $this->extractRolesFromRequest($request);
            $user->setName($name);
            $user->setEmail($email);
            $this->applyAccountTypeFromRequest($user, $request, $roles);
            if (!$user->isClientAccount()) {
                $user->setRole('ROLE_USER');
            }
            $user->setAsanaUserGid(trim($request->request->getString('asanaUserGid')) ?: null);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));

            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->addFlash('success', 'Utilisateur créé.');

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'clients' => $this->clientRepository->findAllOrderedByClientName(),
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_admin_user_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim($request->request->getString('name'));
            $email = trim($request->request->getString('email'));
            $password = (string) $request->request->getString('password');

            if ($name === '' || $email === '') {
                $this->addFlash('error', 'Nom et email sont obligatoires.');

                return $this->render('admin/user/form.html.twig', [
                    'user' => $user,
                ]);
            }

            $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existing && $existing->getId() !== $user->getId()) {
                $this->addFlash('error', 'Un autre utilisateur existe déjà avec cet email.');

                return $this->render('admin/user/form.html.twig', [
                    'user' => $user,
                ]);
            }

            $roles = $this->extractRolesFromRequest($request);
            $user->setName($name);
            $user->setEmail($email);
            $this->applyAccountTypeFromRequest($user, $request, $roles);
            $user->setAsanaUserGid(trim($request->request->getString('asanaUserGid')) ?: null);

            if ($password !== '') {
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Utilisateur modifié.');

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'clients' => $this->clientRepository->findAllOrderedByClientName(),
        ]);
    }

    private function extractRolesFromRequest(Request $request): array
    {
        $roles = [];
        if ($request->request->getBoolean('isAdmin')) {
            $roles[] = User::ROLE_ADMIN;
        }
        if ($request->request->getBoolean('isCommunityManager')) {
            $roles[] = User::ROLE_CM;
        }
        if ($request->request->getBoolean('isEditor')) {
            $roles[] = User::ROLE_EDITOR;
        }

        return $roles;
    }

    /**
     * @param string[] $osmoseRoles
     */
    private function applyAccountTypeFromRequest(User $user, Request $request, array $osmoseRoles): void
    {
        $isClientAccount = $request->request->getBoolean('isClientAccount');
        if ($isClientAccount) {
            $user->setRoles([User::ROLE_CLIENT]);
            $user->setRole(User::ROLE_CLIENT);
            $user->setAsanaUserGid(null);
            $user->clearClientAccesses();

            $clientIds = (array) $request->request->all('client_ids');
            foreach ($clientIds as $raw) {
                $clientId = (int) $raw;
                if ($clientId <= 0) {
                    continue;
                }
                $client = $this->clientRepository->find($clientId);
                if ($client instanceof Client) {
                    $user->addClientAccess($client);
                }
            }

            return;
        }

        $user->setRoles($osmoseRoles);
        $user->setRole('ROLE_USER');
        $user->clearClientAccesses();
    }

    #[Route('/{id}/supprimer', name: 'app_admin_user_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        $current = $this->getUser();
        if ($current instanceof User && $current->getId() === $user->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');

            return $this->redirectToRoute('app_admin_user_index');
        }

        if (!$this->isCsrfTokenValid('delete_user_'.$user->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_admin_user_index');
        }

        $blockers = $this->deletionBlockers(
            $user,
            $this->clientRepository->findActiveClientNamesGroupedByCommunityManager(),
            $this->shootingRequestRepository->countGroupedByAssigneeForActiveClients(),
        );
        if ($blockers !== []) {
            $this->addFlash('error', sprintf(
                'Suppression impossible : %s est %s. Réassignez ces éléments à un autre utilisateur, puis réessayez.',
                $user->getName() ?? 'cet utilisateur',
                implode(' et ', $blockers),
            ));

            return $this->redirectToRoute('app_admin_user_index');
        }

        if (!$current instanceof User) {
            $this->addFlash('error', 'Session expirée. Reconnectez-vous, puis réessayez.');

            return $this->redirectToRoute('app_admin_user_index');
        }

        // Les rattachements archivés ne doivent pas empêcher la suppression. Les
        // colonnes concernées étant NOT NULL, on ne peut pas les vider : on les
        // transfère à l admin qui supprime.
        $archivedClients = $this->clientRepository->findArchivedByCommunityManager($user);
        $archivedShootingRequests = $this->shootingRequestRepository->findForArchivedClientsByAssignee($user);
        foreach ($archivedClients as $archivedClient) {
            $archivedClient->setCommunityManager($current);
        }
        foreach ($archivedShootingRequests as $archivedShootingRequest) {
            $archivedShootingRequest->setAssignedTo($current);
        }

        try {
            $this->entityManager->remove($user);
            // Doctrine applique les UPDATE avant les DELETE : les transferts
            // ci-dessus partent dans la même transaction que la suppression.
            $this->entityManager->flush();
        } catch (ForeignKeyConstraintViolationException) {
            // Filet de sécurité : une contrainte RESTRICT ajoutée plus tard ne doit pas renvoyer une erreur 500.
            $this->addFlash('error', sprintf(
                'Suppression impossible : %s est encore rattaché à des données existantes. Retirez-le de ces éléments, puis réessayez.',
                $user->getName() ?? 'cet utilisateur',
            ));

            return $this->redirectToRoute('app_admin_user_index');
        }

        $this->addFlash('success', $this->deletionSuccessMessage(
            count($archivedClients),
            count($archivedShootingRequests),
        ));

        return $this->redirectToRoute('app_admin_user_index');
    }

    /**
     * Confirme la suppression en nommant les rattachements archivés transférés.
     */
    private function deletionSuccessMessage(int $archivedClientCount, int $archivedShootingRequestCount): string
    {
        $transfers = [];
        if ($archivedClientCount > 0) {
            $transfers[] = sprintf(
                '%d client%s archivé%s',
                $archivedClientCount,
                $archivedClientCount > 1 ? 's' : '',
                $archivedClientCount > 1 ? 's' : '',
            );
        }
        if ($archivedShootingRequestCount > 0) {
            $transfers[] = sprintf(
                '%d demande%s de tournage sur client archivé',
                $archivedShootingRequestCount,
                $archivedShootingRequestCount > 1 ? 's' : '',
            );
        }

        if ($transfers === []) {
            return 'Utilisateur supprimé.';
        }

        return sprintf(
            'Utilisateur supprimé. Rattachements archivés transférés à votre compte : %s.',
            implode(', ', $transfers),
        );
    }

    /**
     * @param iterable<mixed> $users
     *
     * @return array<int, string[]> [id utilisateur => raisons de blocage]
     */
    private function deletionBlockersByUserId(iterable $users): array
    {
        $clientNamesByCm = $this->clientRepository->findActiveClientNamesGroupedByCommunityManager();
        $shootingCountsByAssignee = $this->shootingRequestRepository->countGroupedByAssigneeForActiveClients();

        $blockersByUserId = [];
        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }
            $blockers = $this->deletionBlockers($user, $clientNamesByCm, $shootingCountsByAssignee);
            if ($blockers !== []) {
                $blockersByUserId[(int) $user->getId()] = $blockers;
            }
        }

        return $blockersByUserId;
    }

    /**
     * Raisons empêchant la suppression d'un compte, formulées pour l'admin.
     *
     * Reflète les deux contraintes ON DELETE RESTRICT du schéma :
     * client.community_manager_user_id et shooting_request.assigned_to_id.
     * Les autres liens vers user sont en SET NULL ou CASCADE et ne bloquent pas.
     *
     * Seuls les rattachements actifs sont listés : ceux qui portent sur un
     * client archivé sont transférés automatiquement à la suppression.
     *
     * @param array<int, string[]> $clientNamesByCm
     * @param array<int, int>      $shootingCountsByAssignee
     *
     * @return string[]
     */
    private function deletionBlockers(User $user, array $clientNamesByCm, array $shootingCountsByAssignee): array
    {
        $userId = (int) $user->getId();
        $blockers = [];

        $clientNames = $clientNamesByCm[$userId] ?? [];
        if ($clientNames !== []) {
            $blockers[] = sprintf(
                'CM de %d client%s (%s)',
                count($clientNames),
                count($clientNames) > 1 ? 's' : '',
                implode(', ', $clientNames),
            );
        }

        $shootingCount = $shootingCountsByAssignee[$userId] ?? 0;
        if ($shootingCount > 0) {
            $blockers[] = sprintf(
                'responsable de %d demande%s de tournage',
                $shootingCount,
                $shootingCount > 1 ? 's' : '',
            );
        }

        return $blockers;
    }
}

