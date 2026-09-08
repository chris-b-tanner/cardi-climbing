<?php

namespace App\Service;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The shared shape behind every place a new "contact" gets added to the system other than
 * genuine self-registration: the admin new-member form, sales beneficiary creation, the
 * newsletter signup form, and inbound-email contact capture. Centralised here after a
 * noteableId bug (building a Note against a User that hadn't been flushed yet, so had no id)
 * turned up independently in several of these controllers — one place doing persist-then-flush-
 * then-Note means that class of bug can't recur.
 */
class UserService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserRepository $userRepository,
    ) {}

    /**
     * The account already on file under this email, if any — checking the primary email and both
     * alternate email slots. Use this (not a plain findOneBy(['email' => ...])) before creating a
     * new contact: several call sites used to check only the primary column, so someone already on
     * file under an alternate email could end up with a duplicate account.
     */
    public function findExistingByEmail(string $email): ?User
    {
        return $this->userRepository->findByAnyEmail($email);
    }

    /**
     * Creates a brand-new member with no real login of their own — a random, never-communicated
     * password standing in until they set a real one via "forgot password" (which needs an email
     * on file). Persists and flushes immediately so the returned User has an id, then records a
     * Note describing how/why it was added — do this via createContact() rather than by hand so a
     * Note is never built against an unflushed, id-less User.
     *
     * Callers needing fields not covered here (e.g. phone) can set them on the returned User and
     * flush again themselves; that's safe since the Note has already been created against a real id.
     */
    public function createContact(
        ?string $email,
        ?string $firstName,
        ?string $lastName,
        string $noteContent,
        ?User $addedBy = null,
        ?User $parent = null,
        ?\DateTimeImmutable $dateOfBirth = null,
        ?string $phone = null,
        bool $optIn = false,
    ): User {
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName ?: null);
        $user->setLastName($lastName ?: null);
        $user->setDateOfBirth($dateOfBirth);
        $user->setPhone($phone ?: null);
        $user->setOptIn($optIn);
        // No login for this contact until they set a password via "forgot password" — requires an email on file.
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(32))));

        if ($parent !== null) {
            $user->setParent($parent);
        }

        $this->em->persist($user);
        $this->em->flush(); // assigns $user's id — needed before a Note can reference it via noteableId

        $this->addNote($user, $noteContent, $addedBy);

        return $user;
    }

    /** Records a Note against {$user}. Only call this once $user is guaranteed to already have an id (already flushed, or an existing record). */
    public function addNote(User $user, string $content, ?User $addedBy = null): void
    {
        $note = new Note();
        $note->setNoteable($user);
        $note->setContent($content);
        if ($addedBy !== null) {
            $note->setAddedBy($addedBy);
        }

        $this->em->persist($note);
        $this->em->flush();
    }
}
