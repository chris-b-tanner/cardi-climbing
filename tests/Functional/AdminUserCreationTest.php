<?php

namespace App\Tests\Functional;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Tests\Support\CreatesTestAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Creates a member through the real admin "New member" form and confirms every field entered on
 * it actually shows up on the resulting contact view page — catching a mismatch between what the
 * form submits and what the show page reads/displays.
 */
class AdminUserCreationTest extends WebTestCase
{
    use CreatesTestAdmin;

    private ?int $createdUserId = null;

    public function testCreatedMemberDataAppearsOnShowPage(): void
    {
        $client = static::createClient();
        $client->loginUser($this->findOrCreateAdmin());

        $email = sprintf('phpunit-created-%s@example.test', bin2hex(random_bytes(4)));

        $crawler = $client->request('GET', '/admin/users/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create member')->form([
            'firstName'   => 'Testy',
            'lastName'    => 'McTestface',
            'email'       => $email,
            'phone'       => '01234 567890',
            'dateOfBirth' => '1990-06-15',
        ]);
        // Leave optIn checked (the form's default) — asserted as "Opted in" below.

        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->createdUserId = $this->extractUserIdFromShowPage($client->getRequest()->getPathInfo());

        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('Testy', $content);
        self::assertStringContainsString('McTestface', $content);
        self::assertStringContainsString($email, $content);
        self::assertStringContainsString('01234 567890', $content);
        self::assertStringContainsString('15 Jun 1990', $content);
        self::assertStringContainsString('Opted in', $content);
    }

    private function extractUserIdFromShowPage(string $pathInfo): ?int
    {
        return preg_match('#^/admin/users/(\d+)$#', $pathInfo, $matches) ? (int) $matches[1] : null;
    }

    protected function tearDown(): void
    {
        if ($this->createdUserId !== null) {
            /** @var EntityManagerInterface $em */
            $em   = static::getContainer()->get('doctrine')->getManager();
            $user = $em->getRepository(User::class)->find($this->createdUserId);

            if ($user) {
                $noteRepository = static::getContainer()->get(NoteRepository::class);
                foreach ($noteRepository->findForNoteable(Note::TYPE_MEMBER, $user->getId()) as $note) {
                    $em->remove($note);
                }

                $em->remove($user);
                $em->flush();
            }
        }

        parent::tearDown();
    }
}
