<?php

namespace App\Entity;

use App\Repository\ContentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: ContentRepository::class)]
#[ORM\Table(name: 'content')]
class Content
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $scheduledDate = null;

    #[ORM\ManyToOne(inversedBy: 'contents')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Client $client = null;

    #[ORM\ManyToOne(inversedBy: 'contents')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Format $format = null;

    #[ORM\ManyToOne(inversedBy: 'contents')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Status $status = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    // ---- Video-specific fields (nullable to avoid breaking existing content) ----
    #[ORM\Column(nullable: true)]
    private ?bool $videoHasSubtitles = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $videoEditor = null;

    /** CM déléguée pour cette vidéo (sinon CM du client). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'video_cm_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $videoCmUser = null;

    /** Relecteur sous-titres délégué (sinon CM déléguée ou CM client). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'video_subtitles_reviewer_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $videoSubtitlesReviewer = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $videoRushesUrl = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $videoEditUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $videoEditFilename = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $videoSubmagicUrl = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $videoFinalUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $videoFinalFilename = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $videoThumbnailUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $videoCaption = null;

    #[ORM\Column(length: 32, nullable: true, unique: true)]
    private ?string $asanaTaskGid = null;

    #[ORM\Column(length: 32, nullable: true, unique: true)]
    private ?string $asanaSubtitlesTaskGid = null;

    /**
     * @var Collection<int, ContentComment>
     */
    #[ORM\OneToMany(targetEntity: ContentComment::class, mappedBy: 'content', orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $comments;

    /**
     * @var Collection<int, ContentActionLog>
     */
    #[ORM\OneToMany(targetEntity: ContentActionLog::class, mappedBy: 'content', orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $actionLogs;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->comments = new ArrayCollection();
        $this->actionLogs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getScheduledDate(): ?\DateTimeInterface
    {
        return $this->scheduledDate;
    }

    public function setScheduledDate(\DateTimeInterface $scheduledDate): static
    {
        $this->scheduledDate = $scheduledDate;

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

    public function getFormat(): ?Format
    {
        return $this->format;
    }

    public function setFormat(?Format $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function getStatus(): ?Status
    {
        return $this->status;
    }

    public function setStatus(?Status $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getVideoHasSubtitles(): ?bool
    {
        return $this->videoHasSubtitles;
    }

    public function setVideoHasSubtitles(?bool $videoHasSubtitles): static
    {
        $this->videoHasSubtitles = $videoHasSubtitles;

        return $this;
    }

    public function getVideoEditor(): ?User
    {
        return $this->videoEditor;
    }

    public function setVideoEditor(?User $videoEditor): static
    {
        $this->videoEditor = $videoEditor;

        return $this;
    }

    public function getVideoCmUser(): ?User
    {
        return $this->videoCmUser;
    }

    public function setVideoCmUser(?User $videoCmUser): static
    {
        $this->videoCmUser = $videoCmUser;

        return $this;
    }

    public function getVideoSubtitlesReviewer(): ?User
    {
        return $this->videoSubtitlesReviewer;
    }

    public function setVideoSubtitlesReviewer(?User $videoSubtitlesReviewer): static
    {
        $this->videoSubtitlesReviewer = $videoSubtitlesReviewer;

        return $this;
    }

    public function getVideoRushesUrl(): ?string
    {
        return $this->videoRushesUrl;
    }

    public function setVideoRushesUrl(?string $videoRushesUrl): static
    {
        $this->videoRushesUrl = $videoRushesUrl;

        return $this;
    }

    public function getVideoEditUrl(): ?string
    {
        return $this->videoEditUrl;
    }

    public function setVideoEditUrl(?string $videoEditUrl): static
    {
        $this->videoEditUrl = $videoEditUrl;

        return $this;
    }

    public function getVideoEditFilename(): ?string
    {
        return $this->videoEditFilename;
    }

    public function setVideoEditFilename(?string $videoEditFilename): static
    {
        $this->videoEditFilename = $videoEditFilename;

        return $this;
    }

    public function getVideoSubmagicUrl(): ?string
    {
        return $this->videoSubmagicUrl;
    }

    public function setVideoSubmagicUrl(?string $videoSubmagicUrl): static
    {
        $this->videoSubmagicUrl = $videoSubmagicUrl;

        return $this;
    }

    public function getVideoFinalUrl(): ?string
    {
        return $this->videoFinalUrl;
    }

    public function setVideoFinalUrl(?string $videoFinalUrl): static
    {
        $this->videoFinalUrl = $videoFinalUrl;

        return $this;
    }

    public function getVideoFinalFilename(): ?string
    {
        return $this->videoFinalFilename;
    }

    public function setVideoFinalFilename(?string $videoFinalFilename): static
    {
        $this->videoFinalFilename = $videoFinalFilename;

        return $this;
    }

    public function getVideoThumbnailUrl(): ?string
    {
        return $this->videoThumbnailUrl;
    }

    public function setVideoThumbnailUrl(?string $videoThumbnailUrl): static
    {
        $this->videoThumbnailUrl = $videoThumbnailUrl;

        return $this;
    }

    public function getVideoCaption(): ?string
    {
        return $this->videoCaption;
    }

    public function setVideoCaption(?string $videoCaption): static
    {
        $this->videoCaption = $videoCaption;

        return $this;
    }

    public function getAsanaTaskGid(): ?string
    {
        return $this->asanaTaskGid;
    }

    public function setAsanaTaskGid(?string $asanaTaskGid): static
    {
        $this->asanaTaskGid = $asanaTaskGid === null ? null : trim($asanaTaskGid);

        return $this;
    }

    public function getAsanaSubtitlesTaskGid(): ?string
    {
        return $this->asanaSubtitlesTaskGid;
    }

    public function setAsanaSubtitlesTaskGid(?string $asanaSubtitlesTaskGid): static
    {
        $this->asanaSubtitlesTaskGid = $asanaSubtitlesTaskGid === null ? null : trim($asanaSubtitlesTaskGid);

        return $this;
    }

    /**
     * @return Collection<int, ContentComment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(ContentComment $comment): static
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setContent($this);
        }

        return $this;
    }

    public function removeComment(ContentComment $comment): static
    {
        if ($this->comments->removeElement($comment)) {
            if ($comment->getContent() === $this) {
                $comment->setContent(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ContentActionLog>
     */
    public function getActionLogs(): Collection
    {
        return $this->actionLogs;
    }

    public function addActionLog(ContentActionLog $actionLog): static
    {
        if (!$this->actionLogs->contains($actionLog)) {
            $this->actionLogs->add($actionLog);
            $actionLog->setContent($this);
        }

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
