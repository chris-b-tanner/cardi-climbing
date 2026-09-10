<?php

namespace App\Tests\Functional;

use App\Entity\Certification;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Tests\Support\CreatesTestAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The walk-in kiosk flow: a contact with no email/password gets into the certification wizard by
 * entering the record ID + their surname on a shared tablet, instead of logging in. See
 * KioskController and AdminController::confirmCertification() (where the ID is generated).
 */
class KioskCertificationTest extends WebTestCase
{
    use CreatesTestAdmin;

    private ?int $userId = null;
    private ?int $certificationId = null;
    private ?int $recordId = null;

    public function testCorrectIdAndSurnameLogsInAndOpensTheWizard(): void
    {
        $client = static::createClient();
        [$user, $record] = $this->createWalkInWithInProgressRecord();

        $this->submitKioskForm($client, $record->getId(), strtoupper($user->getLastName())); // case-insensitive

        self::assertResponseRedirects('/account/certifications/' . $record->getId() . '/complete');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Confirm your personal information', $client->getResponse()->getContent());

        // Confirm the session really is authenticated as the walk-in user.
        $client->request('GET', '/account');
        self::assertResponseIsSuccessful();
    }

    public function testWrongSurnameIsRejectedWithAGenericError(): void
    {
        $client = static::createClient();
        [, $record] = $this->createWalkInWithInProgressRecord();

        $this->submitKioskForm($client, $record->getId(), 'DefinitelyNotTheSurname');

        self::assertResponseIsSuccessful(); // re-renders the form with an error, no redirect
        self::assertStringContainsString("match an induction awaiting completion", $client->getResponse()->getContent());

        $client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testExpiredRecordIsRejected(): void
    {
        $client = static::createClient();
        [$user, $record] = $this->createWalkInWithInProgressRecord();

        $em = static::getContainer()->get('doctrine')->getManager();
        $record->setStartedAt(new \DateTimeImmutable('-31 minutes'));
        $em->flush();

        $this->submitKioskForm($client, $record->getId(), $user->getLastName());

        self::assertStringContainsString("match an induction awaiting completion", $client->getResponse()->getContent());
    }

    public function testAlreadyCompletedRecordIsRejected(): void
    {
        $client = static::createClient();
        [$user, $record] = $this->createWalkInWithInProgressRecord();

        $em = static::getContainer()->get('doctrine')->getManager();
        $record->setCompletedAt(new \DateTimeImmutable());
        $em->flush();

        $this->submitKioskForm($client, $record->getId(), $user->getLastName());

        self::assertStringContainsString("match an induction awaiting completion", $client->getResponse()->getContent());
    }

    public function testCompletingViaKioskLogsBackOutAfterwards(): void
    {
        $client = static::createClient();
        [$user, $record] = $this->createWalkInWithInProgressRecord();
        $user->setEmergencyContactName('Someone');
        $user->setEmergencyContactPhone('01234 567890');
        $user->setDateOfBirth(new \DateTimeImmutable('1990-01-01'));
        $user->setPhone('01234 000000');
        $user->setAddressLine1('1 Test Street');
        $user->setTown('Cardigan');
        $user->setPostcode('SA43 1AA');
        static::getContainer()->get('doctrine')->getManager()->flush();

        $this->submitKioskForm($client, $record->getId(), $user->getLastName());
        $client->followRedirect();

        $crawler = $client->getCrawler();
        $form    = $crawler->selectButton('Complete ' . $record->getCertification()->getName())->form();
        $form['signature_consent']->tick();
        $form['signature']->setValue('data:image/png;base64,iVBORw0KGgo=');
        $client->submit($form);

        self::assertResponseRedirects('/kiosk/certification');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('submitted', $client->getResponse()->getContent());

        // Session must no longer be authenticated as the walk-in user.
        $client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testResendingTheInvitationResetsTheKioskWindowForAnExpiredRecord(): void
    {
        $client = static::createClient();
        [$user, $record] = $this->createWalkInWithInProgressRecord();

        $em = static::getContainer()->get('doctrine')->getManager();
        $record->setStartedAt(new \DateTimeImmutable('-31 minutes'));
        $em->flush();

        $this->submitKioskForm($client, $record->getId(), $user->getLastName());
        self::assertStringContainsString('match an induction awaiting completion', $client->getResponse()->getContent(), 'Sanity check: the record should start out expired.');

        $client->loginUser($this->findOrCreateAdmin());
        $crawler = $client->request('GET', '/admin/users/' . $user->getId() . '/certifications/' . $record->getId() . '/edit');
        $form    = $crawler->selectButton('Re-send invitation email')->form();
        $client->submit($form);

        self::assertResponseRedirects();

        $em->clear();
        $refreshed = $em->getRepository(UserCertification::class)->find($record->getId());
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $refreshed->getStartedAt(), 'startedAt should have been reset to now.');

        $this->submitKioskForm($client, $record->getId(), $user->getLastName());
        self::assertResponseRedirects('/account/certifications/' . $record->getId() . '/complete');
    }

    /** @return array{0: User, 1: UserCertification} */
    private function createWalkInWithInProgressRecord(): array
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setFirstName('Walkin');
        $user->setLastName(sprintf('Tester%s', bin2hex(random_bytes(3))));
        $user->setPassword('unused — kiosk auth bypasses password checking entirely');
        $user->setRoles([]);
        $em->persist($user);

        $certification = new Certification();
        $certification->setName(sprintf('TEMP Kiosk Cert %s', bin2hex(random_bytes(3))));
        $em->persist($certification);

        $record = new UserCertification();
        $record->setUser($user);
        $record->setCertification($certification);
        $em->persist($record);

        $em->flush();

        $this->userId          = $user->getId();
        $this->certificationId = $certification->getId();
        $this->recordId        = $record->getId();

        return [$user, $record];
    }

    private function submitKioskForm(KernelBrowser $client, int $recordId, string $surname): void
    {
        $crawler = $client->request('GET', '/kiosk/certification');
        $form    = $crawler->selectButton('Continue')->form([
            'recordId' => $recordId,
            'surname'  => $surname,
        ]);

        $client->submit($form);
    }

    protected function tearDown(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        if ($this->recordId !== null) {
            $record = $em->getRepository(UserCertification::class)->find($this->recordId);
            if ($record) {
                $em->remove($record);
            }
        }
        if ($this->userId !== null) {
            $user = $em->getRepository(User::class)->find($this->userId);
            if ($user) {
                $em->remove($user);
            }
        }
        if ($this->certificationId !== null) {
            $certification = $em->getRepository(Certification::class)->find($this->certificationId);
            if ($certification) {
                $em->remove($certification);
            }
        }
        $em->flush();

        parent::tearDown();
    }
}
