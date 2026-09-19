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

    /** How many real contacts are on file — everyone who hasn't been archived — for the "Community supporters" stat on the homepage. */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * When each *currently* opted-in member joined — for the dashboard's growth chart. Uses
     * today's opt-in snapshot rather than reconstructing historical opt-in status day by day (we
     * don't track opt-in change history precisely enough for that, and don't need to): someone
     * who has since opted out simply isn't counted at all, even on days before they opted out.
     *
     * @return \DateTimeImmutable[]
     */
    public function findOptedInCreatedDates(): array
    {
        return array_map(
            static fn(User $u) => $u->getCreatedAt(),
            $this->createQueryBuilder('u')
                ->where('u.optIn = true')
                ->andWhere('u.deletedAt IS NULL')
                ->getQuery()
                ->getResult(),
        );
    }

    /** Same idea as findOptedInCreatedDates(), but every non-archived contact regardless of opt-in/email — the dashboard's "total members" line. */
    public function findAllCreatedDates(): array
    {
        return array_map(
            static fn(User $u) => $u->getCreatedAt(),
            $this->createQueryBuilder('u')
                ->where('u.deletedAt IS NULL')
                ->getQuery()
                ->getResult(),
        );
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

    /** @param 'id'|'name'|'email' $sort */
    public function search(string $query = '', ?int $tagId = null, ?int $limit = null, string $sort = 'name', string $dir = 'asc', bool $hasMemo = false): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.tags', 't')
            ->leftJoin('u.parent', 'p')
            ->addSelect('t')
            ->addSelect('p');

        if ($hasMemo) {
            $qb->andWhere("u.memo IS NOT NULL AND u.memo != ''");
        }

        if ($query !== '' && ctype_digit($query)) {
            // A purely numeric search is almost always someone looking up a specific contact by
            // ID (eg. from a URL or another record) — matching it against text fields too would
            // just bury the one result they want under unrelated LIKE '%123%' noise.
            $qb->andWhere('u.id = :id')
               ->setParameter('id', (int) $query);
        } elseif ($query !== '') {
            // Deliberately not matching note content — too noisy, brings back too many unrelated
            // results (a note mentioning someone in passing shouldn't surface them here).
            $qb->andWhere('u.email LIKE :q OR u.email2 LIKE :q OR u.email3 LIKE :q OR u.firstName LIKE :q OR u.lastName LIKE :q OR CONCAT(u.firstName, \' \', u.lastName) LIKE :q OR u.company LIKE :q OR u.memo LIKE :q')
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

        $direction = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        if ($sort === 'id') {
            $qb->orderBy('u.id', $direction);
        } elseif ($sort === 'email') {
            $qb->addSelect('COALESCE(u.email, \'\') AS HIDDEN sortEmail')
               ->orderBy('sortEmail', $direction);
        } else {
            $qb->addSelect('COALESCE(u.lastName, u.email) AS HIDDEN sortLast')
               ->addSelect('COALESCE(u.firstName, u.email) AS HIDDEN sortFirst')
               ->orderBy('sortLast', $direction)
               ->addOrderBy('sortFirst', $direction);
        }

        return $qb->getQuery()->getResult();
    }

    /** @param int[] $tagIds Empty = all opted-in members */
    public function findForBulkEmail(array $tagIds = []): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.tags', 't')
            ->addSelect('t')
            ->where('u.optIn = true')
            ->andWhere("u.email IS NOT NULL AND u.email != ''")
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC');

        if ($tagIds) {
            $qb->andWhere('t.id IN (:tagIds)')
               ->setParameter('tagIds', $tagIds)
               ->distinct();
        }

        return $qb->getQuery()->getResult();
    }

    /** Everyone with an active keyholder disarm PIN — see door-access-spec.md § Keyholder disarm PIN. */
    public function findKeyholders(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.keyholderPin IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    /** Whether {pin} is already someone's active keyholder PIN — checked when generating an attendee PIN too, since the two pools must never collide (see § PIN lifecycle). */
    public function keyholderPinExists(string $pin, ?int $excludeUserId = null): bool
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.keyholderPin = :pin')
            ->setParameter('pin', $pin);

        if ($excludeUserId !== null) {
            $qb->andWhere('u.id != :excludeId')->setParameter('excludeId', $excludeUserId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
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

    /**
     * Live duplicate check for the "new member" form — as each field is filled in, the whole
     * current snapshot is sent back here so matches stay accurate (e.g. a first-name-only match
     * is far noisier than a first+last combination once both are filled in). Only fields actually
     * filled in contribute a condition; an empty set of conditions means nothing to check yet.
     */
    public function findDuplicateCandidates(string $firstName, string $lastName, string $email, string $phone, string $company, int $limit = 8): array
    {
        $qb         = $this->createQueryBuilder('u');
        $conditions = [];

        if ($firstName !== '' && $lastName !== '') {
            $conditions[] = "LOWER(CONCAT(u.firstName, ' ', u.lastName)) = LOWER(:fullName)";
            $qb->setParameter('fullName', $firstName . ' ' . $lastName);
        } elseif ($firstName !== '') {
            $conditions[] = 'LOWER(u.firstName) = LOWER(:firstName)';
            $qb->setParameter('firstName', $firstName);
        } elseif ($lastName !== '') {
            $conditions[] = 'LOWER(u.lastName) = LOWER(:lastName)';
            $qb->setParameter('lastName', $lastName);
        }

        if ($email !== '') {
            $conditions[] = '(LOWER(u.email) = LOWER(:email) OR LOWER(u.email2) = LOWER(:email) OR LOWER(u.email3) = LOWER(:email))';
            $qb->setParameter('email', $email);
        }

        if ($phone !== '') {
            $conditions[] = 'u.phone = :phone';
            $qb->setParameter('phone', $phone);
        }

        if ($company !== '') {
            $conditions[] = 'u.company LIKE :company';
            $qb->setParameter('company', '%' . $company . '%');
        }

        if (!$conditions) {
            return [];
        }

        return $qb->andWhere(implode(' OR ', $conditions))
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
