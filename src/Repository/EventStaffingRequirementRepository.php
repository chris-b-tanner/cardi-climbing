<?php

namespace App\Repository;

use App\Entity\EventStaffingRequirement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventStaffingRequirement>
 */
class EventStaffingRequirementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventStaffingRequirement::class);
    }
}
