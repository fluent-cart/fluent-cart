<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsPayPalWebhookRequests;
use Tests\Support\Traits\MocksPayPalHttp;

/**
 * Spec: Cases/paypal-webhooks.md [1-4] (FC-SEC-05).
 *
 * The extension hook fluent_cart/paypal_webhook_received must only ever see
 * payloads whose PayPal signature has been verified (or whose verification was
 * explicitly bypassed by the site), and a rejected delivery must answer 400 so
 * PayPal does not record it as delivered.
 */
class PayPalWebhookVerificationOrderTest extends DatabaseTestCase
{
    use BuildsPayPalWebhookRequests;
    use MocksPayPalHttp;

    /** @var array<int, array> payloads handed to fluent_cart/paypal_webhook_received */
    private array $receivedPayloads = [];

    /** @var callable|null */
    private $hookRecorder = null;

    public function setUp(): void
    {
        parent::setUp();

        if (!defined('FCT_PAYPAL_TEST_WEBHOOK_ID')) {
            define('FCT_PAYPAL_TEST_WEBHOOK_ID', 'WH-FC-SEC-05-TEST');
        }

        $this->installPayPalHttpMock();

        $this->receivedPayloads = [];
        $this->hookRecorder = function ($payload) {
            $this->receivedPayloads[] = $payload;
        };
        add_action('fluent_cart/paypal_webhook_received', $this->hookRecorder, 10, 1);

        $this->installPayPalSignatureHeaders();
    }

    public function tearDown(): void
    {
        $this->restorePayPalSignatureHeaders();
        if ($this->hookRecorder) {
            remove_action('fluent_cart/paypal_webhook_received', $this->hookRecorder, 10);
            $this->hookRecorder = null;
        }
        $this->removePayPalHttpMock();

        parent::tearDown();
    }

    /**
     * Spec: Cases/paypal-webhooks.md [1]
     *   "A forged webhook whose PayPal signature fails verification is answered
     *    with 400 and never reaches the paypal_webhook_received extension hook."
     */
    public function test_forged_webhook_with_invalid_signature_is_rejected_before_the_extension_hook(): void
    {
        $this->scriptPayPal('verify-webhook-signature', 200, ['verification_status' => 'FAILURE']);

        $status = (new IPN())->handleWebhookRequest($this->payPalCaptureCompletedWebhookBody());

        $this->assertSame(1, $this->payPalRequestCount('verify-webhook-signature'), 'the signature must be checked with PayPal exactly once');
        $this->assertSame(400, $status, 'a webhook that fails PayPal signature verification must be answered with HTTP 400');
        $this->assertCount(0, $this->receivedPayloads, 'fluent_cart/paypal_webhook_received must not fire for a webhook whose signature failed verification');
    }

    /**
     * Spec: Cases/paypal-webhooks.md [2]
     *   "A webhook that arrives without the PayPal signature headers is answered
     *    with 400, is never sent to PayPal for verification, and never reaches
     *    the extension hook."
     */
    public function test_webhook_without_signature_headers_is_rejected_before_the_extension_hook(): void
    {
        $this->clearPayPalSignatureHeaders();

        $status = (new IPN())->handleWebhookRequest($this->payPalCaptureCompletedWebhookBody());

        $this->assertSame(0, $this->payPalRequestCount('verify-webhook-signature'), 'a delivery without signature headers must not be sent to PayPal for verification');
        $this->assertSame(400, $status, 'a webhook without PayPal signature headers must be answered with HTTP 400');
        $this->assertCount(0, $this->receivedPayloads, 'fluent_cart/paypal_webhook_received must not fire for a webhook missing its signature headers');
    }

    /**
     * Spec: Cases/paypal-webhooks.md [3]
     *   "A webhook whose PayPal signature verifies is answered with 200 and reaches
     *    the paypal_webhook_received extension hook exactly once with the event."
     */
    public function test_verified_webhook_reaches_the_extension_hook_once_and_is_acknowledged(): void
    {
        $this->scriptPayPal('verify-webhook-signature', 200, ['verification_status' => 'SUCCESS']);

        $status = (new IPN())->handleWebhookRequest($this->payPalCaptureCompletedWebhookBody());

        $this->assertSame(1, $this->payPalRequestCount('verify-webhook-signature'), 'the signature must be checked with PayPal exactly once');
        $this->assertSame(200, $status, 'a verified webhook must be acknowledged with HTTP 200');
        $this->assertCount(1, $this->receivedPayloads, 'fluent_cart/paypal_webhook_received must fire exactly once for a verified webhook');
        $this->assertSame(
            'PAYMENT.CAPTURE.COMPLETED',
            $this->receivedPayloads[0]['data']['event_type'] ?? null,
            'the hook payload must carry the verified event under the data key'
        );
        $this->assertSame(
            $this->payPalCaptureCompletedWebhookBody(),
            $this->receivedPayloads[0]['raw'] ?? null,
            'the hook payload must carry the raw request body under the raw key'
        );
    }

    /**
     * Spec: Cases/paypal-webhooks.md [4]
     *   "When a site explicitly disables PayPal webhook verification, the
     *    extension hook still fires without contacting PayPal."
     */
    public function test_explicitly_disabled_verification_still_reaches_the_extension_hook(): void
    {
        $disable = static function () {
            return 'yes';
        };
        add_filter('fluent_cart/payments/paypal/disable_webhook_verification', $disable, 10, 1);

        try {
            $status = (new IPN())->handleWebhookRequest($this->payPalCaptureCompletedWebhookBody());
        } finally {
            remove_filter('fluent_cart/payments/paypal/disable_webhook_verification', $disable, 10);
        }

        $this->assertSame(0, $this->payPalRequestCount('verify-webhook-signature'), 'disabled verification must not contact PayPal');
        $this->assertSame(200, $status, 'a delivery under disabled verification must be acknowledged with HTTP 200');
        $this->assertCount(1, $this->receivedPayloads, 'fluent_cart/paypal_webhook_received must still fire once when the site disabled verification');
    }
}
