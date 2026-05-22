<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findOneByEmailCaseInsensitive(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower($email))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return User[]
     */
    public function findCommunityManagersOrdered(): array
    {
        return $this->filterByRole($this->findBy([], ['name' => 'ASC']), User::ROLE_CM);
    }

    /**
     * @return User[]
     */
    public function findEditorsOrdered(): array
    {
        return $this->filterByRole($this->findBy([], ['name' => 'ASC']), User::ROLE_EDITOR);
    }

    public function countCommunityManagers(): int
    {
        return count($this->findCommunityManagersOrdered());
    }

    /**
     * @param User[] $users
     *
     * @return User[]
     */
    private function filterByRole(array $users, string $role): array
    {
        return array_values(array_filter(
            $users,
            static fn (User $user): bool => in_array($role, $user->getRoles(), true),
        ));
    }
}
