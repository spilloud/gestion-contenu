<?php

namespace App\Entity;

use App\Repository\AiApiConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paramètres singleton pour l'accès API IA / n8n (Lucy).
 */
#[ORM\Entity(repositoryClass: AiApiConfigRepository::class)]
#[ORM\Table(name: 'ai_api_config')]
class AiApiConfig
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $apiToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $allowedIps = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function setApiToken(?string $apiToken): static
    {
        $this->apiToken = $apiToken === null || $apiToken === '' ? null : $apiToken;

        return $this;
    }

    public function getAllowedIps(): ?string
    {
        return $this->allowedIps;
    }

    public function setAllowedIps(?string $allowedIps): static
    {
        $this->allowedIps = $allowedIps === null || trim($allowedIps) === '' ? null : trim($allowedIps);

        return $this;
    }
}
