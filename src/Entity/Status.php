<?php

namespace App\Entity;

use App\Repository\StatusRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StatusRepository::class)]
#[ORM\Table(name: 'status')]
class Status
{
    public const WORKFLOW_STANDARD = 'standard';
    public const WORKFLOW_VIDEO = 'video';
    public const WORKFLOW_BOTH = 'both';

    public const COLOR_GRAY = 'gray';
    public const COLOR_RED = 'red';
    public const COLOR_ORANGE = 'orange';
    public const COLOR_YELLOW = 'yellow';
    public const COLOR_LIGHT_GREEN = 'lightgreen';
    public const COLOR_GREEN = 'green';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 50)]
    private ?string $color = null;

    #[ORM\Column]
    private ?int $sortOrder = 0;

    #[ORM\Column(length: 16, options: ['default' => self::WORKFLOW_STANDARD])]
    private string $workflow = self::WORKFLOW_STANDARD;

    /**
     * @var Collection<int, Content>
     */
    #[ORM\OneToMany(targetEntity: Content::class, mappedBy: 'status')]
    private Collection $contents;

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

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

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

    public function getWorkflow(): string
    {
        return $this->workflow;
    }

    public function setWorkflow(string $workflow): static
    {
        $this->workflow = $workflow;

        return $this;
    }

    /**
     * @return Collection<int, Content>
     */
    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
