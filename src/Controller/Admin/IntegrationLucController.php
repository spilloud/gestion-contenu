<?php

namespace App\Controller\Admin;

use App\Repository\AiApiConfigRepository;
use App\Service\AiApiSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class IntegrationLucController extends AbstractController
{
    public function __construct(
        private readonly AiApiConfigRepository $aiApiConfigRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AiApiSettingsProvider $aiApiSettingsProvider,
    ) {
    }

    #[Route('/admin/integration-api', name: 'app_admin_integration_api', methods: ['GET', 'POST'])]
    #[Route('/admin/integration-luc', name: 'app_admin_integration_luc', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $config = $this->aiApiConfigRepository->getSingleton();
        $baseUrl = $request->getSchemeAndHttpHost();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('integration_luc', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');

                return $this->redirectToRoute('app_admin_integration_api');
            }

            $action = $request->request->getString('action');
            if ($action === 'generate_token') {
                $token = bin2hex(random_bytes(32));
                $config->setApiToken($token);
                $this->entityManager->flush();
                $this->addFlash('success', 'Nouveau token généré. Copiez-le maintenant : il ne sera plus affiché en entier.');
                $this->addFlash('integration_luc_new_token', $token);

                return $this->redirectToRoute('app_admin_integration_api');
            }

            if ($action === 'save_ips') {
                $config->setAllowedIps($request->request->getString('allowed_ips'));
                $this->entityManager->flush();
                $this->addFlash('success', 'Liste des IP enregistrée.');
            }

            if ($action === 'clear_token') {
                $config->setApiToken(null);
                $this->entityManager->flush();
                $this->addFlash('success', 'Token supprimé. L’API utilisera alors AI_API_TOKEN dans le fichier .env du serveur (si défini).');
            }

            return $this->redirectToRoute('app_admin_integration_api');
        }

        $effectiveToken = $this->aiApiSettingsProvider->getToken();
        $tokenMasked = $this->maskToken($config->getApiToken());
        $tokenSource = $config->getApiToken() !== null && trim((string) $config->getApiToken()) !== ''
            ? 'database'
            : ($effectiveToken !== '' ? 'env' : 'none');

        return $this->render('admin/integration_luc/index.html.twig', [
            'config' => $config,
            'baseUrl' => $baseUrl,
            'apiEndpoint' => $baseUrl.'/api/ai/dashboard-kpi',
            'apiFullExportEndpoint' => $baseUrl.'/api/ai/full-export',
            'effectiveTokenSet' => $effectiveToken !== '',
            'tokenMasked' => $tokenMasked,
            'tokenSource' => $tokenSource,
        ]);
    }

    private function maskToken(?string $token): string
    {
        if ($token === null || $token === '') {
            return '';
        }
        $len = strlen($token);
        if ($len <= 8) {
            return str_repeat('•', min($len, 8));
        }

        return substr($token, 0, 4).'…'.substr($token, -4);
    }
}
