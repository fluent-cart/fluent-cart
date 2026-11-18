<?php
/**
 * A payment method whose add-on (or Pro) is missing is only a placeholder: its settings page must not offer an
 * activation toggle or a Save that "succeeds". The server marks it and refuses to save it.
 */

use FluentCart\App\Hooks\Handlers\GlobalPaymentHandler;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;

return [
    [
        'id'            => 'payment-placeholder-flag',
        'name'          => 'Placeholder payment methods are marked, real ones are not',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $handler = new GlobalPaymentHandler();

            $placeholder = '';
            foreach (['flutterwave', 'mercado_pago', 'sslcommerz', 'razorpay', 'paystack'] as $slug) {
                if (GatewayManager::has($slug) && GlobalPaymentHandler::isPlaceholder(GatewayManager::getInstance($slug))) {
                    $placeholder = $slug;
                    break;
                }
            }
            FcTest::assert($placeholder !== '', 'The test site has at least one add-on payment method that is not installed');

            $settings = $handler->getSettings($placeholder);
            FcTest::assertSame(true, isset($settings['is_placeholder']) && $settings['is_placeholder'], $placeholder . ' is marked as a placeholder');

            // Cash on delivery: a real method whose settings make no outbound calls (Stripe's do).
            $cod = $handler->getSettings('offline_payment');
            FcTest::assertSame(false, !empty($cod['is_placeholder']), 'Cash on delivery is a real payment method');
            FcTest::assertSame(false, GlobalPaymentHandler::isPlaceholder(GatewayManager::getInstance('stripe')), 'Stripe is not a placeholder');
        },
    ],
    [
        'id'            => 'payment-placeholder-no-save',
        'name'          => 'Neither the settings save nor the checkout design save stores a placeholder',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $placeholder = '';
            foreach (['flutterwave', 'mercado_pago', 'sslcommerz', 'razorpay', 'paystack'] as $slug) {
                if (GatewayManager::has($slug) && GlobalPaymentHandler::isPlaceholder(GatewayManager::getInstance($slug))) {
                    $placeholder = $slug;
                    break;
                }
            }
            FcTest::assert($placeholder !== '', 'The test site has at least one add-on payment method that is not installed');

            $previous = get_current_user_id();
            wp_set_current_user(1);
            $before = GatewayManager::getInstance($placeholder)->settings->get();

            $design = FcTest::rest('POST', 'settings/payment-methods/design', [
                'method' => $placeholder, 'checkout_label' => 'FcTest placeholder label', 'checkout_logo' => '',
                'checkout_instructions' => '', 'thank_you_page_instructions' => '',
            ]);
            FcTest::assertSame(423, $design['status'], 'The design save refuses a placeholder');

            $store = FcTest::rest('POST', 'settings/payment-methods', ['method' => $placeholder, 'settings' => ['is_active' => 'yes']]);
            FcTest::assertSame(423, $store['status'], 'The settings save refuses a placeholder');

            FcTest::assertSame($before, GatewayManager::getInstance($placeholder)->settings->get(), 'The placeholder settings did not change');
            wp_set_current_user($previous);
        },
    ],
];
