<?php

namespace App\Controller\Admin;

use App\Entity\CommunityManager;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admin/community-managers')]
class CommunityManagerCrudController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'app_admin_cm_index', methods: ['GET'])]
    public function index(): Response
    {
        $cms = $this->entityManager->getRepository(CommunityManager::class)
            ->findBy([], ['name' => 'ASC']);

        return $this->render('admin/community_manager/index.html.twig', [
            'communityManagers' => $cms,
        ]);
    }

    #[Route('/nouveau', name: 'app_admin_cm_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $cm = new CommunityManager();
        if ($request->isMethod('POST')) {
            $name = $request->request->getString('name');
            $email = trim($request->request->getString('email'));
            $password = $request->request->getString('password');

            if ($email === '' || $password === '') {
                $this->addFlash('error', 'Email et mot de passe sont obligatoires.');

                return $this->render('admin/community_manager/form.html.twig', [
                    'communityManager' => $cm,
                ]);
            }

            $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingUser) {
                $this->addFlash('error', 'Un utilisateur existe deja avec cet email.');

                return $this->render('admin/community_manager/form.html.twig', [
                    'communityManager' => $cm,
                ]);
            }

            $cm->setName($name);
            $cm->setEmail($email);

            $user = new User();
            $user->setName($name);
            $user->setEmail($email);
            $user->setRole('ROLE_USER');
            $user->setRoles([User::ROLE_CM]);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));

            $this->entityManager->persist($user);
            $this->entityManager->persist($cm);
            $this->entityManager->flush();
            $this->addFlash('success', 'Community manager cree avec compte utilisateur.');
            return $this->redirectToRoute('app_admin_cm_index');
        }

        return $this->render('admin/community_manager/form.html.twig', [
            'communityManager' => $cm,
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_admin_cm_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(CommunityManager $communityManager, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $oldEmail = $communityManager->getEmail();
            $name = $request->request->getString('name');
            $email = trim($request->request->getString('email'));
            $password = $request->request->getString('password');

            if ($email === '') {
                $this->addFlash('error', 'L\'email est obligatoire pour permettre la connexion.');

                return $this->render('admin/community_manager/form.html.twig', [
                    'communityManager' => $communityManager,
                ]);
            }

            $userRepository = $this->entityManager->getRepository(User::class);
            $user = $oldEmail ? $userRepository->findOneBy(['email' => $oldEmail]) : null;

            // Prevent email collision with another account
            $userWithNewEmail = $userRepository->findOneBy(['email' => $email]);
            if ($userWithNewEmail && (!$user || $userWithNewEmail->getId() !== $user->getId())) {
                $this->addFlash('error', 'Un autre utilisateur existe deja avec cet email.');

                return $this->render('admin/community_manager/form.html.twig', [
                    'communityManager' => $communityManager,
                ]);
            }

            if (!$user) {
                if ($password === '') {
                    $this->addFlash('error', 'Ce community manager n\'a pas de compte. Indique un mot de passe pour en creer un.');

                    return $this->render('admin/community_manager/form.html.twig', [
                        'communityManager' => $communityManager,
                    ]);
                }

                $user = new User();
                $user->setRole('ROLE_USER');
                $user->setRoles([User::ROLE_CM]);
                $this->entityManager->persist($user);
            }

            $communityManager->setName($name);
            $communityManager->setEmail($email);

            $user->setName($name);
            $user->setEmail($email);
            if ($password !== '') {
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Community manager et compte utilisateur modifies.');
            return $this->redirectToRoute('app_admin_cm_index');
        }

        return $this->render('admin/community_manager/form.html.twig', [
            'communityManager' => $communityManager,
        ]);
    }
}
