<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ResetPasswordController extends AbstractController
{
    private const RESET_TTL_HOURS = 48;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot_password', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');

                return $this->redirectToRoute('app_forgot_password_request');
            }

            $email = mb_strtolower(trim($request->request->getString('email')));
            if ($email === '') {
                $this->addFlash('error', 'Indiquez votre adresse email.');

                return $this->render('security/forgot_password.html.twig');
            }

            $user = $this->userRepository->findOneByEmailCaseInsensitive($email);
            if ($user instanceof User) {
                $token = bin2hex(random_bytes(32));
                $user
                    ->setPasswordResetToken($token)
                    ->setPasswordResetRequestedAt(new \DateTimeImmutable());

                $this->entityManager->flush();

                $resetUrl = $this->generateUrl(
                    'app_reset_password',
                    ['token' => $token],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );

                if (!$this->sendPasswordResetEmail($user, $resetUrl)) {
                    $user->clearPasswordReset();
                    $this->entityManager->flush();
                    $this->addFlash('error', 'L\'envoi de l\'email a échoué. Vérifiez la configuration d\'envoi (PHP mail / serveur SMTP) ou contactez un administrateur.');

                    return $this->render('security/forgot_password.html.twig', [
                        'last_email' => $email,
                    ]);
                }
            }

            $this->addFlash('success', 'Si un compte correspond à cet email, un message avec un lien de réinitialisation vient d\'être envoyé.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig');
    }

    #[Route('/reinitialiser-mot-de-passe/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request): Response
    {
        $user = $this->userRepository->findOneBy(['passwordResetToken' => $token]);
        if (!$user instanceof User) {
            $this->addFlash('error', 'Ce lien de réinitialisation est invalide ou a déjà été utilisé.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $requestedAt = $user->getPasswordResetRequestedAt();
        if ($requestedAt === null
            || $requestedAt < (new \DateTimeImmutable())->modify('-'.self::RESET_TTL_HOURS.' hours')) {
            $user->clearPasswordReset();
            $this->entityManager->flush();
            $this->addFlash('error', 'Ce lien a expiré. Demandez une nouvelle réinitialisation.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset_password', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');

                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }

            $password = (string) $request->request->getString('password');
            $password2 = (string) $request->request->getString('password_confirm');
            if (strlen($password) < 8) {
                $this->addFlash('error', 'Le mot de passe doit contenir au moins 8 caractères.');

                return $this->render('security/reset_password.html.twig', ['token' => $token]);
            }
            if ($password !== $password2) {
                $this->addFlash('error', 'Les deux mots de passe ne correspondent pas.');

                return $this->render('security/reset_password.html.twig', ['token' => $token]);
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $user->clearPasswordReset();
            $this->entityManager->flush();

            $this->addFlash('success', 'Votre mot de passe a été mis à jour. Vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', ['token' => $token]);
    }

    private function sendPasswordResetEmail(User $user, string $resetUrl): bool
    {
        $to = $user->getEmail();
        if ($to === null || $to === '') {
            return false;
        }

        $body = $this->renderView('emails/reset_password.txt.twig', [
            'resetUrl' => $resetUrl,
            'userName' => $user->getName(),
            'ttlHours' => self::RESET_TTL_HOURS,
        ]);

        $fromRaw = getenv('MAILER_FROM');
        $from = ($fromRaw !== false && trim((string) $fromRaw) !== '')
            ? trim((string) $fromRaw)
            : 'noreply@osmose-marketing.ch';

        $subjectLine = 'Réinitialisation de votre mot de passe';
        $subject = '=?UTF-8?B?'.base64_encode($subjectLine).'?=';

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: '.$from,
        ];

        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }
}
