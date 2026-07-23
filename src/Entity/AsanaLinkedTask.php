<?php

namespace App\Entity;

use App\Repository\AsanaLinkedTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AsanaLinkedTaskRepository::class)]
#[ORM\Table(name: 'asana_linked_task')]
class AsanaLinkedTask
{
    public const KIND_DERUSH_FOLLOWUP = 'derush_followup';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $taskGid = null;

    #[ORM\Column(length: 32)]
    private ?string $kind = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Client $client = null;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $contentIds = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAtLucy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTaskGid(): ?string
    {
        return $this->taskGid;
    }

    public function setTaskGid(string $taskGid): static
    {
        $this->taskGid = trim($taskGid);

        return $this;
    }

    public function getKind(): ?string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function getContentIds(): array
    {
        return $this->contentIds;
    }

    /**
     * @param list<int> $contentIds
     */
    public function setContentIds(array $contentIds): static
    {
        $this->contentIds = array_values(array_unique(array_map('intval', $contentIds)));

        return $this;
    }

    public function getCompletedAtLucy(): ?\DateTimeImmutable
    {
        return $this->completedAtLucy;
    }

    public function setCompletedAtLucy(?\DateTimeImmutable $completedAtLucy): static
    {
        $this->completedAtLucy = $completedAtLucy;

        return $this;
    }

    public function isOpen(): bool
    {
        return $this->completedAtLucy === null;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
