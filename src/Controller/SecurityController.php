<?php

namespace App\Controller;

use App\Entity\CommunityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route(path: '/profil', name: 'app_profile_redirect', methods: ['GET'])]
    public function profileRedirect(EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $cm = $entityManager->getRepository(CommunityManager::class)->findOneBy([
            'email' => $user->getUserIdentifier(),
        ]);

        if ($cm) {
            return $this->redirectToRoute('app_admin_cm_edit', ['id' => $cm->getId()]);
        }

        $this->addFlash('error', 'Aucun profil editable associe a ce compte.');
        return $this->redirectToRoute('app_dashboard');
    }
}
