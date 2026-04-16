<?php

namespace App\Entity;

use App\Repository\ClientPageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientPageRepository::class)]
#[ORM\Table(name: 'client_page')]
class ClientPage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'clientPage', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?Client $client = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $importantInfo = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $ideas = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, TodoItem>
     */
    #[ORM\OneToMany(targetEntity: TodoItem::class, mappedBy: 'clientPage', orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $todoItems;

    public function __construct()
    {
        $this->todoItems = new ArrayCollection();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getImportantInfo(): ?string
    {
        return $this->importantInfo;
    }

    public function setImportantInfo(?string $importantInfo): static
    {
        $this->importantInfo = $importantInfo;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getIdeas(): ?string
    {
        return $this->ideas;
    }

    public function setIdeas(?string $ideas): static
    {
        $this->ideas = $ideas;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, TodoItem>
     */
    public function getTodoItems(): Collection
    {
        return $this->todoItems;
    }

    public function addTodoItem(TodoItem $todoItem): static
    {
        if (!$this->todoItems->contains($todoItem)) {
            $this->todoItems->add($todoItem);
            $todoItem->setClientPage($this);
        }

        return $this;
    }

    public function removeTodoItem(TodoItem $todoItem): static
    {
        if ($this->todoItems->removeElement($todoItem)) {
            if ($todoItem->getClientPage() === $this) {
                $todoItem->setClientPage(null);
            }
        }

        return $this;
    }
}
