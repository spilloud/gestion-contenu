<?php

namespace App\Entity;

use App\Repository\TodoItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TodoItemRepository::class)]
#[ORM\Table(name: 'todo_item')]
class TodoItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private ?string $label = null;

    #[ORM\Column]
    private ?bool $done = false;

    #[ORM\Column]
    private ?int $sortOrder = 0;

    #[ORM\ManyToOne(inversedBy: 'todoItems')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ClientPage $clientPage = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isDone(): ?bool
    {
        return $this->done;
    }

    public function setDone(bool $done): static
    {
        $this->done = $done;

        return $this;
    }

    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getClientPage(): ?ClientPage
    {
        return $this->clientPage;
    }

    public function setClientPage(?ClientPage $clientPage): static
    {
        $this->clientPage = $clientPage;

        return $this;
    }
}
