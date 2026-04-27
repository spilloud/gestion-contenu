<?php

namespace App\Controller\Admin;

use App\Entity\User;
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
    ) {
    }

    #[Route('', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(): Response
    {
        $users = $this->entityManager->getRepository(User::class)->findBy([], ['name' => 'ASC']);

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
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
            $user->setRoles($roles);
            $user->setRole('ROLE_USER');
            $user->setAsanaUserGid(trim($request->request->getString('asanaUserGid')) ?: null);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));

            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->addFlash('success', 'Utilisateur créé.');

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
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
            $user->setRoles($roles);
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

        $this->entityManager->remove($user);
        $this->entityManager->flush();
        $this->addFlash('success', 'Utilisateur supprimé.');

        return $this->redirectToRoute('app_admin_user_index');
    }
}

