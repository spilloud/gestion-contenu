<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_CM = 'ROLE_CM';
    public const ROLE_EDITOR = 'ROLE_EDITOR';
    public const ROLE_CLIENT = 'ROLE_CLIENT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\Column(length: 50)]
    private ?string $role = 'ROLE_USER';

    /**
     * @var string[]|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $roles = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetRequestedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $asanaUserGid = null;

    /**
     * Comptes clients : accès lecture seule à un ou plusieurs clients.
     *
     * @var Collection<int, Client>
     */
    #[ORM\ManyToMany(targetEntity: Client::class)]
    #[ORM\JoinTable(name: 'client_user_access')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'client_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $clientAccesses;

    private ?string $plainPassword = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->clientAccesses = new ArrayCollection();
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles ?? [];

        // Backward compatibility: keep legacy single role if present.
        if ($this->role !== null && $this->role !== '' && !in_array($this->role, $roles, true)) {
            $roles[] = $this->role;
        }

        // Les comptes clients sont volontairement isolés: pas de ROLE_USER implicite.
        // L'accès est géré uniquement via ROLE_CLIENT + /agenda.
        $isClient = in_array(self::ROLE_CLIENT, $roles, true);
        if (!$isClient && !in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return string[]|null
     */
    public function getRolesRaw(): ?array
    {
        return $this->roles;
    }

    /**
     * @param string[]|null $roles
     */
    public function setRoles(?array $roles): static
    {
        $this->roles = $roles === null ? null : array_values(array_unique($roles));

        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? '';
    }

    public function getPasswordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function setPasswordResetToken(?string $passwordResetToken): static
    {
        $this->passwordResetToken = $passwordResetToken;

        return $this;
    }

    public function getPasswordResetRequestedAt(): ?\DateTimeImmutable
    {
        return $this->passwordResetRequestedAt;
    }

    public function setPasswordResetRequestedAt(?\DateTimeImmutable $passwordResetRequestedAt): static
    {
        $this->passwordResetRequestedAt = $passwordResetRequestedAt;

        return $this;
    }

    public function clearPasswordReset(): static
    {
        $this->passwordResetToken = null;
        $this->passwordResetRequestedAt = null;

        return $this;
    }

    public function isCommunityManager(): bool
    {
        return in_array(self::ROLE_CM, $this->getRoles(), true);
    }

    public function isEditor(): bool
    {
        return in_array(self::ROLE_EDITOR, $this->getRoles(), true);
    }

    public function isClientAccount(): bool
    {
        return in_array(self::ROLE_CLIENT, $this->getRoles(), true);
    }

    /**
     * @return Collection<int, Client>
     */
    public function getClientAccesses(): Collection
    {
        return $this->clientAccesses;
    }

    public function clearClientAccesses(): static
    {
        $this->clientAccesses->clear();

        return $this;
    }

    public function addClientAccess(Client $client): static
    {
        if (!$this->clientAccesses->contains($client)) {
            $this->clientAccesses->add($client);
        }

        return $this;
    }

    public function removeClientAccess(Client $client): static
    {
        $this->clientAccesses->removeElement($client);

        return $this;
    }

    public function getAsanaUserGid(): ?string
    {
        return $this->asanaUserGid;
    }

    public function setAsanaUserGid(?string $asanaUserGid): static
    {
        $this->asanaUserGid = $asanaUserGid === null ? null : trim($asanaUserGid);

        return $this;
    }
}
