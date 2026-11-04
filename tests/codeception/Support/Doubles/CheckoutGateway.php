<?php

namespace Tests\Support\Doubles;

use FluentCart\App\Models\Cart;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;

/**
 * Deterministic checkout gateway for Functional tests. It performs no I/O.
 */
class CheckoutGateway extends AbstractPaymentGateway
{
    public array $supportedFeatures = ['payment'];

    /**
     * When set, makePaymentFromPaymentInstance() returns this instead of
     * completing the cart — a WP_Error reproduces a server-observed gateway
     * failure. Set through PlacesCheckoutOrders::declineStubPayments(); the
     * trait's removeStubCheckoutGateway() clears it so no Cest inherits it.
     *
     * @var \WP_Error|array|null
     */
    public static $forcedPaymentResult = null;

    public function __construct()
    {
        parent::__construct(new CheckoutGatewaySettings());
    }

    public function meta(): array
    {
        return [
            'title'       => 'wp-browser checkout stub',
            'label'       => 'wp-browser checkout stub',
            'route'       => 'wp_browser_stub',
            'slug'        => 'wp_browser_stub',
            'description' => 'In-process test gateway',
            'logo'        => '',
            'icon'        => '',
            'brand_color' => '#000000',
            'upcoming'    => false,
            'status'      => true,
        ];
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        if (static::$forcedPaymentResult !== null) {
            return static::$forcedPaymentResult;
        }

        Cart::query()
            ->where('order_id', $paymentInstance->order->id)
            ->where('stage', '!=', 'completed')
            ->update([
                'stage'        => 'completed',
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ]);

        // Mirror the shipped gateways (Cod, Stripe, PayPal): the customer is sent to
        // the receipt URL keyed by the transaction uuid, so the response identifies
        // the recorded transaction — and through it the order — like production does.
        return [
            'status'      => 'success',
            'message'     => 'Order placed through wp-browser checkout stub',
            'redirect_to' => $paymentInstance->transaction
                ? $paymentInstance->transaction->getSuccessUrl()
                : 'https://example.test/checkout-complete',
        ];
    }

    public function handleIPN()
    {
        return null;
    }

    public function getOrderInfo(array $data)
    {
        return ['status' => 'stub'];
    }

    public function fields()
    {
        return [];
    }
}
