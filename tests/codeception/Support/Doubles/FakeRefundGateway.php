<?php

namespace Tests\Support\Doubles;

use FluentCart\App\Modules\PaymentMethods\Cod\CodSettingsBase;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;

/**
 * Scriptable gateway with the `refund` feature. Register in setUp and ALWAYS call
 * `FakeRefundGateway::remove()` in tearDown — the gateway registry is global.
 *
 * `$nextResult` is a vendor refund id string, a WP_Error, or a Throwable to throw.
 */
class FakeRefundGateway extends AbstractPaymentGateway
{
    const SLUG = 'fake_refund_gw';

    public array $supportedFeatures = ['payment', 'refund'];

    /** @var string|\WP_Error|\Throwable */
    public static $nextResult = 're_fake_1';

    public static $refundCalls = 0;

    /** @var callable|null Runs inside processRefund before it returns, e.g. to simulate an early webhook */
    public static $beforeReturn = null;

    public static function register(array $features = ['payment', 'refund']): self
    {
        static::$nextResult = 're_fake_1';
        static::$refundCalls = 0;
        static::$beforeReturn = null;

        $gateway = new static(new CodSettingsBase());
        $gateway->supportedFeatures = $features;
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
            'title'    => 'Fake Refund Gateway',
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

    public function processRefund($transaction, $amount, $args)
    {
        static::$refundCalls++;

        if (static::$nextResult instanceof \Throwable) {
            throw static::$nextResult;
        }

        if (is_callable(static::$beforeReturn)) {
            call_user_func(static::$beforeReturn, $transaction, $amount);
        }

        return static::$nextResult;
    }
}
