<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\Support\FunctionalTestCase;

/**
 * Bulk email: the compose screen, its live preview and audience count, and the admin-only
 * sent-email history. Deliberately never saves or sends — sending queues real mail.
 */
class AdminEmailControllerTest extends FunctionalTestCase
{
    public function testTeamCanComposeAndPreviewAnEmail(): void
    {
        $this->loginAsTeam();

        $this->assertPageLoads('/admin/email/compose');

        $this->client->request('POST', '/admin/email/preview', [
            'subject' => 'PHPUnit preview subject',
            'body'    => '<p>PHPUnit preview body</p>',
        ]);
        self::assertResponseIsSuccessful();
        $this->assertSee('PHPUnit preview subject');

        $this->client->request('POST', '/admin/email/count');
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^\d+$/', $this->client->getResponse()->getContent());
    }

    public function testOnlyAdminsCanViewSentEmailHistory(): void
    {
        $this->loginAsTeam();
        $this->assertForbidden('/admin/settings/emails');

        $this->loginAsAdmin();
        $this->assertPageLoads('/admin/settings/emails');
    }
}
