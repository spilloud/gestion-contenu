<?php

namespace App\Entity;

use App\Repository\ShootingRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShootingRequestRepository::class)]
#[ORM\Table(name: 'shooting_request')]
class ShootingRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Client $client = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $shootingDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Consignes vidéaste (HTML léger : gras, listes…). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $videographerNotes = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $location = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $assignedTo = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $asanaTaskGid = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $asanaTaskCompletedAt = null;

    /** @var Collection<int, Content> */
    #[ORM\ManyToMany(targetEntity: Content::class)]
    #[ORM\JoinTable(name: 'shooting_request_video')]
    private Collection $videos;

    public function __construct()
    {
        $this->videos = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getShootingDate(): ?\DateTimeInterface
    {
        return $this->shootingDate;
    }

    public function setShootingDate(\DateTimeInterface $shootingDate): static
    {
        $this->shootingDate = $shootingDate;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getVideographerNotes(): ?string
    {
        return $this->videographerNotes;
    }

    public function setVideographerNotes(?string $videographerNotes): static
    {
        $this->videographerNotes = $videographerNotes;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getAsanaTaskGid(): ?string
    {
        return $this->asanaTaskGid;
    }

    public function setAsanaTaskGid(?string $asanaTaskGid): static
    {
        $this->asanaTaskGid = $asanaTaskGid;

        return $this;
    }

    public function getAsanaTaskCompletedAt(): ?\DateTimeImmutable
    {
        return $this->asanaTaskCompletedAt;
    }

    public function setAsanaTaskCompletedAt(?\DateTimeImmutable $asanaTaskCompletedAt): static
    {
        $this->asanaTaskCompletedAt = $asanaTaskCompletedAt;

        return $this;
    }

    /**
     * @return Collection<int, Content>
     */
    public function getVideos(): Collection
    {
        return $this->videos;
    }

    public function addVideo(Content $video): static
    {
        if (!$this->videos->contains($video)) {
            $this->videos->add($video);
        }

        return $this;
    }

    public function removeVideo(Content $video): static
    {
        $this->videos->removeElement($video);

        return $this;
    }
}
