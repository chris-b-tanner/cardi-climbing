<?php

namespace App\Tests\Functional\Controller\Api;

use App\Repository\UserRepository;
use App\Tests\Support\FunctionalTestCase;

/** Postmark's inbound-email and open-tracking webhooks, authenticated by a secret in the URL. */
class WebhookControllerTest extends FunctionalTestCase
{
    public function testWrongSecretIsRejected(): void
    {
        $this->postJson('/webhook/inbound/not-the-secret', ['From' => 'someone@example.test']);
        self::assertResponseStatusCodeSame(401);

        $this->postJson('/webhook/postmark-open/not-the-secret', []);
        self::assertResponseStatusCodeSame(401);
    }

    public function testInboundEmailFromANewSenderCreatesAContact(): void
    {
        $email = sprintf('phpunit-inbound-%s@example.test', $this->uniqueSuffix());

        $this->postJson('/webhook/inbound/' . $this->secret(), [
            'FromFull' => ['Email' => $email, 'Name' => 'Inbound Sender'],
            'TextBody' => 'Hello from PHPUnit',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('created', $this->json()['status']);

        $user = $this->service(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $this->track($user);
        self::assertSame('Inbound', $user->getFirstName());
    }

    public function testInboundEmailWithNoSenderIsUnprocessable(): void
    {
        $this->postJson('/webhook/inbound/' . $this->secret(), ['TextBody' => 'No sender']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOpenEventForAnUnknownEmailIsAcknowledged(): void
    {
        $this->postJson('/webhook/postmark-open/' . $this->secret(), [
            'RecordType' => 'Open',
            'Metadata'   => ['ref' => 'phpunit-' . $this->uniqueSuffix()],
        ]);

        // Anything unmatched still gets a 200, so Postmark doesn't keep retrying it.
        self::assertResponseIsSuccessful();
    }

    private function secret(): string
    {
        return $_ENV['WEBHOOK_SECRET'] ?? $_SERVER['WEBHOOK_SECRET'];
    }

    private function postJson(string $url, array $payload): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
    }
}
