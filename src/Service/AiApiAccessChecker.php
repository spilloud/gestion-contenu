<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authentification et contrôle IP pour les endpoints lecture seule sous /api/ai (Lucy, n8n).
 */
final class AiApiAccessChecker
{
    public function __construct(
        private readonly AiApiSettingsProvider $aiApiSettingsProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function validate(Request $request): ?JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $ipCheck = $this->evaluateAllowedIp($request);
        if (!$ipCheck['ok']) {
            $this->logger->warning('API AI : IP non autorisée (403).', [
                'seenClientIps' => $ipCheck['candidates'],
                'allowedList' => $ipCheck['allowed'],
                'path' => $request->getPathInfo(),
            ]);

            $payload = [
                'error' => 'Forbidden',
                'reason' => 'ip_not_allowed',
            ];
            if ($this->isAiApiIpDebugEnabled()) {
                $payload['debug'] = [
                    'seenClientIps' => $ipCheck['candidates'],
                    'allowedList' => $ipCheck['allowed'],
                    'hint' => 'Symfony (pas Laravel). Vérifier Admin → Intégration API, ou nginx real_ip / X-Forwarded-For.',
                ];
            }

            return new JsonResponse($payload, 403);
        }

        return null;
    }

    private function isAuthorized(Request $request): bool
    {
        $configuredToken = $this->aiApiSettingsProvider->getToken();
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

    /**
     * @return array{ok: bool, candidates: string[], allowed: string[]}
     */
    private function evaluateAllowedIp(Request $request): array
    {
        $raw = $this->aiApiSettingsProvider->getAllowedIpsRaw();
        if ($raw === '') {
            return ['ok' => true, 'candidates' => [], 'allowed' => []];
        }

        $allowedIps = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($allowedIps === []) {
            return ['ok' => true, 'candidates' => [], 'allowed' => []];
        }

        $candidates = [];
        foreach ($request->getClientIps() as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '') {
                $candidates[] = $ip;
            }
        }
        $fallback = trim((string) $request->getClientIp());
        if ($fallback !== '' && !in_array($fallback, $candidates, true)) {
            array_unshift($candidates, $fallback);
        }
        $candidates = array_values(array_unique($candidates));

        foreach ($allowedIps as $allowed) {
            foreach ($candidates as $candidate) {
                if ($this->sameIpAddress($allowed, $candidate)) {
                    return ['ok' => true, 'candidates' => $candidates, 'allowed' => $allowedIps];
                }
            }
        }

        return ['ok' => false, 'candidates' => $candidates, 'allowed' => $allowedIps];
    }

    private function isAiApiIpDebugEnabled(): bool
    {
        $raw = getenv('AI_API_DEBUG_IP');
        if ($raw === false) {
            $raw = $_ENV['AI_API_DEBUG_IP'] ?? $_SERVER['AI_API_DEBUG_IP'] ?? '0';
        }
        $v = strtolower(trim((string) $raw));

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Compare deux adresses IPv4/IPv6 en normalisant les formes équivalentes (:: vs :0: etc.).
     */
    private function sameIpAddress(string $allowed, string $client): bool
    {
        $allowed = trim($allowed);
        $client = trim($client);
        if ($allowed === '' || $client === '') {
            return false;
        }
        if ($allowed === $client) {
            return true;
        }

        $binAllowed = @inet_pton($allowed);
        $binClient = @inet_pton($client);
        if ($binAllowed !== false && $binClient !== false && strlen($binAllowed) === strlen($binClient)) {
            return hash_equals($binAllowed, $binClient);
        }

        return false;
    }
}
