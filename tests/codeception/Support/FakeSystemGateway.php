<?php

namespace Tests\Support;

use FluentCart\App\Modules\PaymentMethods\Cod\CodSettingsBase;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Services\Payments\PaymentInstance;

/**
 * Scriptable fake gateway with the `system_subscription` capability — the test
 * harness for the auto-charge engine (SystemChargeService) and the store-managed
 * checkout gates.
 *
 * Register with `FakeSystemGateway::register()` in setUp and ALWAYS call
 * `FakeSystemGateway::remove()` in tearDown — the gateway registry is global.
 *
 * Charge behavior is scripted via `FakeSystemGateway::$nextResult`:
 *   'success'    — mimics the real contract: marks the pending transaction
 *                  succeeded and confirms through StatusHelper::syncOrderStatuses()
 *                  (so renewal_paid → handleRenewalPaid run), returns true
 *   'processing' — records a vendor charge id and returns 'processing'
 *   'fail'       — returns WP_Error('card_declined', …)
 */
class FakeSystemGateway extends AbstractPaymentGateway
{
    const SLUG = 'fake_system_gw';

    // Models a Stripe-like auto-capable gateway: `subscriptions` is declared
    // directly (no vendor subscription module is attached — the admission and
    // charge paths under test only read the capability flags).
    public array $supportedFeatures = ['payment', 'subscriptions', 'manual_subscription', 'system_subscription'];

    /** @var string 'success' | 'processing' | 'fail' */
    public static $nextResult = 'success';

    /** @var int Charge attempts received since the last reset */
    public static $chargeCalls = 0;

    /** @var array<int, array{order_id: int|null, subscription_id: int|null, attempt: int|null}> */
    public static $chargeRenewalArgs = [];

    /** @var bool Advertise SetupIntent-style save-without-charge support */
    public static $setupWithoutCharge = false;

    public static function register(): self
    {
        static::$nextResult = 'success';
        static::$chargeCalls = 0;
        static::$chargeRenewalArgs = [];
        static::$setupWithoutCharge = false;

        $gateway = new static(new CodSettingsBase());
        GatewayManager::getInstance()->register(self::SLUG, $gateway);

        return $gateway;
    }

    public static function remove(): void
    {
        GatewayManager::getInstance()->remove(self::SLUG);
    }

    public function meta(): array
    {
        return [
            'slug'     => self::SLUG,
            'route'    => self::SLUG,
            'title'    => 'Fake System Gateway',
            'upcoming' => false,
        ];
    }

    public function fields()
    {
        return [];
    }

    public function makePaymentFromPaymentInstance($paymentInstance)
    {
        return [];
    }

    public function handleIPN()
    {
    }

    public function getOrderInfo(array $data)
    {
    }

    public function supportsSetupWithoutCharge(): bool
    {
        return static::$setupWithoutCharge;
    }

    public function chargeRenewal(PaymentInstance $paymentInstance, $args = [])
    {
        static::$chargeCalls++;

        // Recorded BEFORE any scripted early return, so a failing charge still
        // exposes what the caller forwarded. Gateways build their idempotency
        // key from the attempt slot, so "which attempt was sent" is part of the
        // retry contract and not an implementation detail.
        static::$chargeRenewalArgs[] = [
            'order_id'        => $paymentInstance->order ? (int) $paymentInstance->order->id : null,
            'subscription_id' => $paymentInstance->subscription ? (int) $paymentInstance->subscription->id : null,
            'attempt'         => isset($args['attempt']) ? (int) $args['attempt'] : null,
        ];

        if (static::$nextResult === 'fail') {
            return new \WP_Error('card_declined', 'Your card was declined (fake).');
        }

        $attempt = isset($args['attempt']) ? (int) $args['attempt'] : 1;

        if (static::$nextResult === 'processing') {
            $paymentInstance->transaction->update([
                'vendor_charge_id' => 'ch_fake_processing_' . $attempt,
            ]);
            return 'processing';
        }

        // Mirror the real contract: confirm through the normal charge path so
        // syncOrderStatuses fires renewal_invoice_paid → handleInvoicePaid.
        $paymentInstance->transaction->update([
            'status'           => 'succeeded',
            'vendor_charge_id' => 'ch_fake_' . $attempt . '_' . wp_generate_password(4, false),
        ]);
        (new StatusHelper($paymentInstance->order))->syncOrderStatuses($paymentInstance->transaction);

        return true;
    }
}
