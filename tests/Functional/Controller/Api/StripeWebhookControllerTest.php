<?php

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Support\FunctionalTestCase;

/**
 * Stripe's payment/refund webhook. Only the signature gate is covered here — a correctly signed
 * event needs STRIPE_WEBHOOK_SECRET, which the test environment deliberately leaves unset. The
 * outcomes the webhook applies live in StripePaymentService::markSucceeded()/markFailed().
 */
class StripeWebhookControllerTest extends FunctionalTestCase
{
    public function testUnsignedEventIsRejected(): void
    {
        $this->client->request('POST', '/webhook/stripe', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_phpunit']],
        ]));

        self::assertResponseStatusCodeSame(400);
    }
}
