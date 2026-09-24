<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\UserCertification;
use App\Tests\Support\CreatesTestAdmin;
use App\Tests\Support\CreatesTestCertification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * The full certification lifecycle for a member with their own email on file: an admin starts it
 * (AdminController::confirmCertification()), the member gets it by clicking the emailed magic link
 * (CertificationMailer::sendInvitation() / MagicLinkController) rather than logging in directly,
 * completes the declarations + signature themselves (AccountController::completeCertification()),
 * and an admin signs it off (AdminController::approveCertification()) — the point where a PDF
 * snapshot is generated and emailed back. See KioskCertificationTest for the no-email walk-in
 * variant of steps 2–3 (kiosk ID+surname instead of a magic link).
 *
 * No real email ever goes anywhere (MAILER_DSN=null://null) — Symfony's mailer message logger
 * still records what *would* have been sent, which is what lets this test recover the actual magic
 * link token from the invitation email's body rather than fetching it straight from MagicLinkService,
 * so the real end-to-end path (including the email content itself) is what's under test.
 */
class CertificationLifecycleTest extends WebTestCase
{
    use CreatesTestAdmin;
    use CreatesTestCertification;
    use MailerAssertionsTrait;

    private ?int $userId = null;
    private ?int $certificationId = null;
    private ?int $recordId = null;

    public function testAdminStartsMemberCompletesViaEmailedMagicLinkThenAdminApproves(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keep one container/EM across requests — needed to read back the mailer log and DB state consistently between steps
        $admin  = $this->findOrCreateAdmin();
        $member = $this->createMemberWithCompleteProfile();
        $certification = $this->createCertification([
            'I confirm I have read and understood the safety briefing.',
            'I confirm all information given is accurate to the best of my knowledge.',
        ]);
        $this->certificationId = $certification->getId();
        $declarations = $certification->getDeclarations();

        // ── Admin starts the certification ──────────────────────────────
        $client->loginUser($admin);

        $client->request('GET', '/admin/users/' . $member->getId() . '/certifications/new');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($certification->getName(), $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/admin/users/' . $member->getId() . '/certifications/' . $certification->getId() . '/confirm');
        $form    = $crawler->selectButton('Start certification')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/' . $member->getId());

        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserCertification $record */
        $record = $em->getRepository(UserCertification::class)->findOneBy(['user' => $member, 'certification' => $certification]);
        $this->recordId = $record->getId();

        self::assertSame(UserCertification::STATUS_IN_PROGRESS, $record->getStatus());

        // ── The invitation email carries a real magic link — recover it exactly as the member
        //    would. Checked *before* following the redirect below: the test client resets the
        //    mailer's collected messages on every request (Symfony's MessageLoggerListener is a
        //    kernel.reset service, cleared on kernel.terminate — which fires after every request
        //    this client makes, not just on reboot), so the email has to be read back immediately
        //    after the request that actually sent it. ──────────────────────────────────────────
        self::assertEmailCount(1);
        /** @var Email $invitation */
        $invitation = self::getMailerMessage(0);
        self::assertSame($member->getEmail(), $invitation->getTo()[0]->getAddress());
        self::assertStringContainsString($certification->getName(), (string) $invitation->getSubject());

        $magicLinkPath = $this->extractMagicLinkPath($invitation);

        $client->followRedirect();
        self::assertStringContainsString('emailed a link to complete it', $client->getResponse()->getContent());

        // ── Member clicks it (same browser session — a magic link works whether the previous
        //    session was logged out or, as here, still logged in as someone else; see
        //    MagicLinkController's own docblock) and lands straight on the wizard ──────────────
        $client->request('GET', $magicLinkPath);
        self::assertResponseRedirects('/account/certifications/' . $record->getId() . '/complete');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Confirm your personal information', $client->getResponse()->getContent());

        foreach ($declarations as $declaration) {
            self::assertStringContainsString($declaration->getText(), $client->getResponse()->getContent());
        }

        // ── Complete the wizard: every declaration ticked, signature consent, and a signature ──
        // Posted directly (rather than via a scraped Form object) because several `declarations[]`
        // checkboxes sharing one name don't collapse into a single settable multi-value field the
        // way a real <select multiple> would — DomCrawler hands them back as separate fields.
        $crawler = $client->getCrawler();
        $csrfToken = $crawler->filter('#complete-form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/account/certifications/' . $record->getId() . '/complete', [
            '_csrf_token'        => $csrfToken,
            'declarations'       => array_map(static fn($d) => (string) $d->getId(), $declarations->toArray()),
            'signature_consent'  => '1',
            'signature'          => 'data:image/png;base64,iVBORw0KGgo=',
        ]);

        self::assertResponseRedirects('/account#certifications');

        $em->clear();
        $record = $em->getRepository(UserCertification::class)->find($this->recordId);
        self::assertTrue($record->isSubmitted());
        self::assertSame(UserCertification::STATUS_PENDING_APPROVAL, $record->getStatus());
        self::assertSame('data:image/png;base64,iVBORw0KGgo=', $record->getSignature());
        self::assertCount(2, $record->getAgreedDeclarations());
        self::assertSame(
            array_map(static fn($d) => $d->getText(), $declarations->toArray()),
            array_map(static fn($d) => $d->getText(), $record->getAgreedDeclarations()->toArray()),
            'Every declaration the member ticked should be recorded against the record, in order.',
        );

        // ── The signature makes it into the certificate PDF template ───────────────────────────
        $twig = static::getContainer()->get('twig');
        $certificateHtml = $twig->render('pdf/certification_certificate.html.twig', ['record' => $record]);
        self::assertStringContainsString('src="data:image/png;base64,iVBORw0KGgo="', $certificateHtml);
        foreach ($declarations as $declaration) {
            self::assertStringContainsString($declaration->getText(), $certificateHtml);
        }

        // ── Admin reviews and approves ───────────────────────────────────
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/users/' . $member->getId() . '/certifications/' . $record->getId() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Awaiting approval', $client->getResponse()->getContent());
        self::assertStringContainsString($declarations->first()->getText(), $client->getResponse()->getContent());

        $form = $crawler->selectButton('Approve')->form();
        $client->submit($form);

        self::assertResponseRedirects();

        // ── Approval emails the member a completed PDF copy — checked immediately, for the same
        //    reason as the invitation email above: the mailer log only reflects this last request. ──
        self::assertEmailCount(1);
        /** @var Email $completion */
        $completion = self::getMailerMessage(0);
        self::assertSame($member->getEmail(), $completion->getTo()[0]->getAddress());
        self::assertEmailAttachmentCount($completion, 1);

        $attachment = $completion->getAttachments()[0];
        self::assertSame('pdf', $attachment->getMediaSubtype());
        self::assertStringStartsWith('%PDF-', $attachment->getBody(), 'The attached certificate should be a real, generated PDF.');

        $client->followRedirect();
        self::assertStringContainsString('Certification approved', $client->getResponse()->getContent());

        $em->clear();
        $record = $em->getRepository(UserCertification::class)->find($this->recordId);
        self::assertTrue($record->isApproved());
        self::assertTrue($record->isHeld());
        self::assertSame(UserCertification::STATUS_COMPLETED, $record->getStatus());
        self::assertSame($admin->getId(), $record->getApprovedBy()->getId());
    }

    public function testMemberMustAgreeToEveryDeclarationBeforeCompleting(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keep one container/EM across requests — needed to read back the mailer log and DB state consistently between steps
        $member = $this->createMemberWithCompleteProfile();
        $certification = $this->createCertification([
            'First declaration.',
            'Second declaration.',
        ]);
        $this->certificationId = $certification->getId();
        $declarations = $certification->getDeclarations();

        $em = static::getContainer()->get('doctrine')->getManager();
        $record = new UserCertification();
        $record->setUser($member);
        $record->setCertification($certification);
        $em->persist($record);
        $em->flush();
        $this->recordId = $record->getId();

        $client->loginUser($member);

        $crawler = $client->request('GET', '/account/certifications/' . $record->getId() . '/complete');
        $csrfToken = $crawler->filter('#complete-form input[name="_csrf_token"]')->attr('value');

        // Only agree to the first of two declarations.
        $client->request('POST', '/account/certifications/' . $record->getId() . '/complete', [
            '_csrf_token'       => $csrfToken,
            'declarations'      => [(string) $declarations->first()->getId()],
            'signature_consent' => '1',
            'signature'         => 'data:image/png;base64,iVBORw0KGgo=',
        ]);

        self::assertResponseIsSuccessful(); // re-renders the form with an error, no redirect
        self::assertStringContainsString('agree to all of the declarations', $client->getResponse()->getContent());

        $em->clear();
        $record = $em->getRepository(UserCertification::class)->find($this->recordId);
        self::assertFalse($record->isSubmitted());
        self::assertCount(0, $record->getAgreedDeclarations());
    }

    public function testAdminCannotApproveARecordThatHasNotBeenSubmitted(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keep one container/EM across requests — needed to read back the mailer log and DB state consistently between steps
        $admin  = $this->findOrCreateAdmin();
        $member = $this->createMemberWithCompleteProfile();
        $certification = $this->createCertification([]);
        $this->certificationId = $certification->getId();

        $em = static::getContainer()->get('doctrine')->getManager();
        $record = new UserCertification();
        $record->setUser($member);
        $record->setCertification($certification);
        $em->persist($record);
        $em->flush();
        $this->recordId = $record->getId();

        $client->loginUser($admin);

        // No "Approve" button exists yet for an in-progress record, so there's no form to scrape a
        // real CSRF token from directly. The token itself only depends on the session + intention
        // string, not on the record's state — so mark it submitted just long enough to render (and
        // scrape) the real Approve form's token, then put it back to in_progress before actually
        // using that token, to genuinely test the "not submitted" guard rather than CSRF checking.
        $record->setCompletedAt(new \DateTimeImmutable());
        $em->flush();
        $crawler = $client->request('GET', '/admin/users/' . $member->getId() . '/certifications/' . $record->getId() . '/edit');
        $csrfToken = $crawler->selectButton('Approve')->form()['_csrf_token']->getValue();

        $record->setCompletedAt(null);
        $em->flush();

        $client->request('POST', '/admin/users/' . $member->getId() . '/certifications/' . $record->getId() . '/approve', [
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/admin/users/' . $member->getId() . '/certifications/' . $record->getId() . '/edit');
        $client->followRedirect();
        self::assertStringContainsString('cannot be approved', $client->getResponse()->getContent());

        $em->clear();
        $record = $em->getRepository(UserCertification::class)->find($this->recordId);
        self::assertFalse($record->isApproved());
    }

    private function createMemberWithCompleteProfile(): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setFirstName('Cert');
        $user->setLastName(sprintf('Tester%s', bin2hex(random_bytes(3))));
        $user->setEmail(sprintf('phpunit-cert-%s@example.test', bin2hex(random_bytes(4))));
        $user->setPassword('unused — this test authenticates via loginUser()/magic link, never a password');
        $user->setRoles([User::ROLE_MEMBER]);
        // Satisfies completeCertification()'s "missing profile fields" gate up front, so the test
        // can go straight to the declarations/signature step rather than the profile-edit detour.
        $user->setEmergencyContactName('Someone');
        $user->setEmergencyContactPhone('01234 567890');
        $user->setDateOfBirth(new \DateTimeImmutable('1990-01-01'));
        $user->setPhone('01234 000000');
        $user->setAddressLine1('1 Test Street');
        $user->setTown('Cardigan');
        $user->setPostcode('SA43 1AA');

        $em->persist($user);
        $em->flush();

        $this->userId = $user->getId();

        return $user;
    }

    /** Pulls the real "/go/{token}" magic-link path out of the invitation email's text body — see MagicLinkService for the token shape. */
    private function extractMagicLinkPath(Email $email): string
    {
        self::assertMatchesRegularExpression('#/go/[0-9a-f]{96}#', (string) $email->getTextBody(), 'Expected a magic link URL in the invitation email.');
        preg_match('#(/go/[0-9a-f]{96})#', (string) $email->getTextBody(), $matches);

        return $matches[1];
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
        $em->flush();

        if ($this->certificationId !== null) {
            $this->removeCertification($this->certificationId);
        }

        parent::tearDown();
    }
}
