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

    public function search(string $query = '', ?int $tagId = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.tags', 't')
            ->leftJoin('u.notes', 'n')
            ->addSelect('t');

        if ($query !== '') {
            $qb->andWhere('u.email LIKE :q OR u.email2 LIKE :q OR u.email3 LIKE :q OR u.firstName LIKE :q OR u.lastName LIKE :q OR CONCAT(u.firstName, \' \', u.lastName) LIKE :q OR u.memo LIKE :q OR n.content LIKE :q')
               ->setParameter('q', '%' . $query . '%')
               ->distinct();
        }

        if ($tagId !== null) {
            $qb->andWhere('t.id = :tagId')
               ->setParameter('tagId', $tagId);
        }

        return $qb
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
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
