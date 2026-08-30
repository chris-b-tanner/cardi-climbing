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

    public function search(string $query = '', ?int $tagId = null, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.tags', 't')
            ->leftJoin('u.notes', 'n')
            ->leftJoin('u.parent', 'p')
            ->addSelect('t')
            ->addSelect('p');

        if ($query !== '') {
            $qb->andWhere('u.email LIKE :q OR u.email2 LIKE :q OR u.email3 LIKE :q OR u.firstName LIKE :q OR u.lastName LIKE :q OR CONCAT(u.firstName, \' \', u.lastName) LIKE :q OR u.memo LIKE :q OR n.content LIKE :q')
               ->setParameter('q', '%' . $query . '%')
               ->distinct();
        }

        if ($tagId !== null) {
            $qb->andWhere('t.id = :tagId')
               ->setParameter('tagId', $tagId);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb
            ->addSelect('COALESCE(u.lastName, u.email) AS HIDDEN sortLast')
            ->addSelect('COALESCE(u.firstName, u.email) AS HIDDEN sortFirst')
            ->orderBy('sortLast', 'ASC')
            ->addOrderBy('sortFirst', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @param int[] $tagIds Empty = all opted-in members */
    public function findForBulkEmail(array $tagIds = []): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.tags', 't')
            ->addSelect('t')
            ->where('u.optIn = true')
            ->andWhere('u.email IS NOT NULL')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC');

        if ($tagIds) {
            $qb->andWhere('t.id IN (:tagIds)')
               ->setParameter('tagIds', $tagIds)
               ->distinct();
        }

        return $qb->getQuery()->getResult();
    }

    public function findByAnyEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.email = :email')
            ->orWhere('u.email2 = :email')
            ->orWhere('u.email3 = :email')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Everyone on the team — admins and volunteers with admin-area access — sorted by name.
     *
     * Filters in PHP rather than in DQL since roles are stored as a JSON column,
     * which isn't reliably queryable across DB engines.
     *
     * @return User[]
     */
    public function findTeam(): array
    {
        $all = $this->createQueryBuilder('u')
            ->addSelect('COALESCE(u.lastName, u.email) AS HIDDEN sortLast')
            ->addSelect('COALESCE(u.firstName, u.email) AS HIDDEN sortFirst')
            ->orderBy('sortLast', 'ASC')
            ->addOrderBy('sortFirst', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($all, static function (User $user) {
            $roles = $user->getRoles();
            return in_array(User::ROLE_ADMIN, $roles, true) || in_array(User::ROLE_TEAM, $roles, true);
        }));
    }

    /**
     * IDs of every member who has at least one dependent. One query, used to flag parents in a
     * member list without loading each row's dependents collection (which would be one query per row).
     *
     * @return int[]
     */
    public function findParentIds(): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('IDENTITY(u.parent) AS parentId')
            ->where('u.parent IS NOT NULL')
            ->distinct()
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn(array $row) => (int) $row['parentId'], $rows);
    }

    /**
     * Members matching $query who could be recorded as a dependent of $parent: everyone except
     * $parent themselves and anyone who already has dependents of their own (no dependent chains).
     * Members already dependent of someone else are included, since assigning them here
     * reassigns them (a dependent can only have one parent).
     *
     * @return User[]
     */
    public function searchPotentialDependents(User $parent, string $query, int $limit = 20): array
    {
        // Over-fetch since some results get filtered out below, then trim back to $limit.
        $candidates = $this->search($query, null, $limit + 20);

        $filtered = array_values(array_filter(
            $candidates,
            static fn(User $candidate) => $candidate !== $parent && !$candidate->hasDependents(),
        ));

        return array_slice($filtered, 0, $limit);
    }

    public function findByFullName(string $firstName, string $lastName, int $excludeId): array
    {
        return $this->createQueryBuilder('u')
            ->where('LOWER(u.firstName) = LOWER(:fn)')
            ->andWhere('LOWER(u.lastName) = LOWER(:ln)')
            ->andWhere('u.id != :id')
            ->setParameter('fn', $firstName)
            ->setParameter('ln', $lastName)
            ->setParameter('id', $excludeId)
            ->getQuery()
            ->getResult();
    }
}
