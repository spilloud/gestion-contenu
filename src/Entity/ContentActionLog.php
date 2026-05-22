<?php

namespace App\Entity;

use App\Repository\ContentActionLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentActionLogRepository::class)]
#[ORM\Table(name: 'content_action_log')]
#[ORM\Index(columns: ['content_id', 'created_at'], name: 'idx_content_action_log_content_created')]
class ContentActionLog
{
    public const TYPE_CREATED = 'created';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_TRANSITION = 'transition';
    public const TYPE_STEP_BACK = 'step_back';
    public const TYPE_SCHEDULED_DATE_CHANGED = 'scheduled_date_changed';
    public const TYPE_EDITOR_CHANGED = 'editor_changed';
    public const TYPE_MANUAL_STATUS = 'manual_status';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'actionLogs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Content $content = null;

    #[ORM\Column(length: 64)]
    private string $actionType = self::TYPE_STATUS_CHANGED;

    #[ORM\Column(length: 500)]
    private string $label = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $detail = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): ?Content
    {
        return $this->content;
    }

    public function setContent(?Content $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function setActionType(string $actionType): static
    {
        $this->actionType = $actionType;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function setDetail(?string $detail): static
    {
        $this->detail = $detail;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
