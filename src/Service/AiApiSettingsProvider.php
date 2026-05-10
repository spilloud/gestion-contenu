<?php

namespace App\Service;

use App\Entity\AiApiConfig;
use App\Repository\AiApiConfigRepository;

/**
 * Lit token + IPs : base de données (admin) en priorité, sinon variables d'environnement.
 */
class AiApiSettingsProvider
{
    public function __construct(
        private readonly AiApiConfigRepository $aiApiConfigRepository,
    ) {
    }

    public function getToken(): string
    {
        $db = $this->aiApiConfigRepository->find(AiApiConfig::SINGLETON_ID);
        $t = $db?->getApiToken();
        if (is_string($t) && trim($t) !== '') {
            return trim($t);
        }

        return $this->readEnv('AI_API_TOKEN');
    }

    public function getAllowedIpsRaw(): string
    {
        $db = $this->aiApiConfigRepository->find(AiApiConfig::SINGLETON_ID);
        $ips = $db?->getAllowedIps();
        if (is_string($ips) && trim($ips) !== '') {
            return trim($ips);
        }

        return $this->readEnv('AI_API_ALLOWED_IPS');
    }

    private function readEnv(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        if ($value === false) {
            return '';
        }

        return trim((string) $value);
    }
}
