<?php

namespace App\Tests\Functional;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Tests\Support\CreatesTestAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Edits an existing member through the real admin "Edit person" form and confirms the submitted
 * changes persist and show up on the resulting contact view page. This is exactly the flow broken
 * by a production bug where the manual access-card-link form was nested inside the main "Edit
 * person" form — HTML forms can't nest, so the browser's closing </form> for the inner one closed
 * the outer one early, silently detaching "Save changes" from any form at all (fixed by moving
 * that form to be a sibling instead). If that regressed, selectButton('Save changes')->form()
 * below would throw rather than the test failing on a content assertion, which is the point.
 *
 * Deliberately doesn't touch the keyholder PIN or access-card fields/actions — the PIN field is
 * submitted with its rendered default (blank, for a fresh test user), and the access-card actions
 * are separate forms/endpoints entirely, never interacted with here.
 */
class AdminUserEditTest extends WebTestCase
{
    use CreatesTestAdmin;

    private ?int $userId = null;

    public function testEditedMemberDataAppearsOnShowPage(): void
    {
        $client = static::createClient();
        $client->loginUser($this->findOrCreateAdmin());

        $this->userId = $this->createContactToEdit();
        $newEmail = sprintf('phpunit-edited-%s@example.test', bin2hex(random_bytes(4)));

        $crawler = $client->request('GET', '/admin/users/' . $this->userId . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form([
            'firstName' => 'Edited',
            'lastName'  => 'Testerson',
            'email'     => $newEmail,
            'phone'     => '07700 900123',
            'memo'      => 'Updated via functional test.',
        ]);

        $client->submit($form);
        self::assertResponseRedirects('/admin/users/' . $this->userId);
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('Edited', $content);
        self::assertStringContainsString('Testerson', $content);
        self::assertStringContainsString($newEmail, $content);
        self::assertStringContainsString('07700 900123', $content);
        self::assertStringContainsString('Updated via functional test.', $content);
    }

    private function createContactToEdit(): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setEmail(sprintf('phpunit-pre-edit-%s@example.test', bin2hex(random_bytes(4))));
        $user->setFirstName('Original');
        $user->setLastName('Name');
        $user->setPassword('unused — never logged into directly');

        $em->persist($user);
        $em->flush();

        return $user->getId();
    }

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            /** @var EntityManagerInterface $em */
            $em   = static::getContainer()->get('doctrine')->getManager();
            $user = $em->getRepository(User::class)->find($this->userId);

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
