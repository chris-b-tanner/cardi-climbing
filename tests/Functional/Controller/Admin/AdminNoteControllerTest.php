<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Note;
use App\Repository\NoteRepository;
use App\Tests\Support\FunctionalTestCase;

/** Notes attached to records — here via a member's page, the most common place they're added. */
class AdminNoteControllerTest extends FunctionalTestCase
{
    public function testTeamCanAddANoteToAMember(): void
    {
        $this->loginAsTeam();
        $member = $this->createUser();

        $content = 'PHPUnit note ' . $this->uniqueSuffix();
        $this->submitFormAt('/admin/users/' . $member->getId(), '/admin/notes/member/' . $member->getId(), ['content' => $content]);
        self::assertResponseRedirects();

        $notes = $this->service(NoteRepository::class)->findForNoteable(Note::TYPE_MEMBER, $member->getId());
        self::assertContains($content, array_map(static fn (Note $n) => $n->getContent(), $notes));
    }

    public function testStaffListReturnsTheTeam(): void
    {
        $team = $this->loginAsTeam();

        $this->get('/admin/notes/staff');
        self::assertResponseIsSuccessful();
        self::assertContains($team->getId(), array_column($this->json(), 'id'));
    }
}
