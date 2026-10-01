<?php

namespace App\EventListener;

use App\Entity\Attendee;
use App\Entity\Membership;
use App\Entity\Note;
use App\Entity\Payment;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\UserCertification;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Stamps User::$lastActivityAt whenever a record of an interaction with that contact is created —
 * a note about them (directly, or on one of their bookings/orders), a payment, booking, sale,
 * membership or certification. Done at flush time rather than in each controller/service so no
 * code path that creates one of these can forget to, and it drives the stale-contact reminders
 * (Tag::isStale()) on the members list.
 */
#[AsDoctrineListener(event: Events::onFlush)]
class LastActivityListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $touched = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $user = $this->userFor($entity, $em);
            if ($user !== null) {
                $touched[spl_object_id($user)] = $user;
            }
        }

        if (!$touched) {
            return;
        }

        $now  = new \DateTimeImmutable();
        $meta = $em->getClassMetadata(User::class);

        foreach ($touched as $user) {
            if ($uow->getEntityState($user) !== UnitOfWork::STATE_MANAGED || $uow->isScheduledForDelete($user)) {
                continue;
            }

            $user->setLastActivityAt($now);
            $uow->recomputeSingleEntityChangeSet($meta, $user);
        }
    }

    private function userFor(object $entity, EntityManagerInterface $em): ?User
    {
        return match (true) {
            $entity instanceof Payment,
            $entity instanceof Attendee,
            $entity instanceof SalesOrder,
            $entity instanceof Membership,
            $entity instanceof UserCertification => $entity->getUser(),
            $entity instanceof Note => $this->userForNote($entity, $em),
            default => null,
        };
    }

    private function userForNote(Note $note, EntityManagerInterface $em): ?User
    {
        return match ($note->getNoteableType()) {
            Note::TYPE_MEMBER   => $em->find(User::class, $note->getNoteableId()),
            Note::TYPE_ATTENDEE => $em->find(Attendee::class, $note->getNoteableId())?->getUser(),
            Note::TYPE_ORDER    => $em->find(SalesOrder::class, $note->getNoteableId())?->getUser(),
            default             => null,
        };
    }
}
