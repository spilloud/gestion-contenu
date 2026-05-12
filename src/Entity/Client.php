<?php

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'client')]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $asanaProjectGid = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isArchived = false;

    #[ORM\ManyToOne(inversedBy: 'clients')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CommunityManager $communityManager = null;

    // 1 monteur par client (modifiable plus tard si besoin)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $editor = null;

    /**
     * @var Collection<int, Content>
     */
    #[ORM\OneToMany(targetEntity: Content::class, mappedBy: 'client', orphanRemoval: true)]
    #[ORM\OrderBy(['scheduledDate' => 'ASC'])]
    private Collection $contents;

    #[ORM\OneToOne(mappedBy: 'client', cascade: ['persist', 'remove'])]
    private ?ClientPage $clientPage = null;

    public function __construct()
    {
        $this->contents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getAsanaProjectGid(): ?string
    {
        return $this->asanaProjectGid;
    }

    public function setAsanaProjectGid(?string $asanaProjectGid): static
    {
        $this->asanaProjectGid = $asanaProjectGid === null ? null : trim($asanaProjectGid);

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $isArchived): static
    {
        $this->isArchived = $isArchived;

        return $this;
    }

    public function getCommunityManager(): ?CommunityManager
    {
        return $this->communityManager;
    }

    public function setCommunityManager(?CommunityManager $communityManager): static
    {
        $this->communityManager = $communityManager;

        return $this;
    }

    public function getEditor(): ?User
    {
        return $this->editor;
    }

    public function setEditor(?User $editor): static
    {
        $this->editor = $editor;

        return $this;
    }

    /**
     * @return Collection<int, Content>
     */
    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function addContent(Content $content): static
    {
        if (!$this->contents->contains($content)) {
            $this->contents->add($content);
            $content->setClient($this);
        }

        return $this;
    }

    public function removeContent(Content $content): static
    {
        if ($this->contents->removeElement($content)) {
            if ($content->getClient() === $this) {
                $content->setClient(null);
            }
        }

        return $this;
    }

    public function getClientPage(): ?ClientPage
    {
        return $this->clientPage;
    }

    public function setClientPage(?ClientPage $clientPage): static
    {
        if ($clientPage === null && $this->clientPage !== null) {
            $this->clientPage->setClient(null);
        }

        if ($clientPage !== null && $clientPage->getClient() !== $this) {
            $clientPage->setClient($this);
        }

        $this->clientPage = $clientPage;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
