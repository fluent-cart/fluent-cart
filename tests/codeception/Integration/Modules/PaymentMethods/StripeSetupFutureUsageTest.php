<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Stripe;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\WpDieCapture;

class StripeSetupFutureUsageTest extends WPTestCase
{
    /** @dataProvider checkoutCases */
    public function test_subscription_elements_keep_off_session_usage( $managementMode, $trialDays, $saveCard, $expectedMode, $clientUsage = null, $expectedUsage = 'off_session' ): void
    {
        $stripeSettings = function ( $settings ) {
            $settings['checkout_mode'] = 'onsite';
            $settings['test_publishable_key'] = 'pk_test_usage_regression';
            $settings['live_publishable_key'] = 'pk_test_usage_regression';

            return $settings;
        };
        $management = function () use ( $managementMode ) {
            return $managementMode;
        };
        $clientUsageFilter = function ( $value ) use ( $clientUsage ) {
            return $clientUsage === null ? $value : $clientUsage;
        };
        add_filter( 'fluent_cart/stripe_settings', $stripeSettings );
        add_filter( 'fluent_cart/stripe/client_setup_future_usage', $clientUsageFilter );
        add_filter( 'fluent_cart/subscription/management_mode', $management );
        add_filter( 'fluent_cart/subscriptions/system_collection_enabled', '__return_true' );
        $previousHash = App::request()->get( Helper::INSTANT_CHECKOUT_URL_PARAM );

        try {
            $product = ( new ProductFactory() )->withVariantAttributes( [
                'payment_type' => 'subscription',
                'item_price'   => 1000,
                'other_info'   => [
                    'payment_type'    => 'subscription',
                    'repeat_interval' => 'monthly',
                    'times'           => 0,
                    'trial_days'      => $trialDays,
                    'signup_fee'      => 0,
                ],
            ] )->create();
            $item = CartHelper::generateCartItemFromVariation( $product->variants->first(), 1 );
            $cart = ( new CartFactory() )->withCartItems( [ $item ] )->create( [
                'cart_group' => 'instant',
            ] );
            App::request()->merge( [ Helper::INSTANT_CHECKOUT_URL_PARAM => $cart->cart_hash ] );
            CartResource::resetCartCache();
            CartCheckoutHelper::resetInstance();

            $result = WpDieCapture::capture( function () use ( $saveCard ) {
                ( new Stripe() )->getOrderInfo( $saveCard ? [ 'save_payment_method' => 'yes' ] : [] );
            } );

            $this->assertSame( 200, $result['status'], 'Checkout configuration must succeed.' );
            $this->assertSame( $expectedMode, $result['json']['intent']['mode'], 'Use setup only when a store-managed trial has nothing payable.' );
            $this->assertSame( $expectedUsage, $result['json']['intent']['setup_future_usage'] ?? null, 'Elements must match the intent usage regardless of optional card-saving consent.' );
            if ( $expectedMode === 'setup' ) {
                $this->assertArrayNotHasKey( 'amount', $result['json']['intent'], 'Setup mode must not contain a payment amount.' );
                $this->assertFalse( $result['json']['consent_required'], 'The free trial shows the renewal disclosure without a consent checkbox.' );
                $this->assertNotEmpty( $result['json']['system_consent'], 'The free trial must still show the automatic-renewal disclosure.' );
            }
        } finally {
            remove_filter( 'fluent_cart/stripe_settings', $stripeSettings );
            remove_filter( 'fluent_cart/stripe/client_setup_future_usage', $clientUsageFilter );
            remove_filter( 'fluent_cart/subscription/management_mode', $management );
            remove_filter( 'fluent_cart/subscriptions/system_collection_enabled', '__return_true' );
            App::request()->merge( [ Helper::INSTANT_CHECKOUT_URL_PARAM => $previousHash ] );
            CartResource::resetCartCache();
            CartCheckoutHelper::resetInstance();
        }
    }

    public static function checkoutCases(): array
    {
        return [
            'system trial with save card'    => [ 'store_managed', 7, true, 'setup' ],
            'system trial without save card' => [ 'store_managed', 7, false, 'setup' ],
            'system paid with save card'     => [ 'store_managed', 0, true, 'payment' ],
            'system paid without save card'  => [ 'store_managed', 0, false, 'payment' ],
            'gateway trial with save card'   => [ 'gateway_managed', 7, true, 'subscription' ],
            'gateway trial without save'     => [ 'gateway_managed', 7, false, 'subscription' ],
            'filter cannot change setup'        => [ 'store_managed', 7, true, 'setup', 'on_session' ],
            'filter cannot change subscription' => [ 'gateway_managed', 7, true, 'subscription', 'on_session' ],
            'filter changes one-time payment'   => [ 'store_managed', 0, true, 'payment', 'on_session', 'on_session' ],
        ];
    }
}
