<?php

namespace Tests\Integration\Services;

use FluentCart\App\Modules\PaymentMethods\Cod\Cod;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Stripe;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * {{order.payment_instructions}} prints the gateway's thank-you page
 * instructions, but only for offline payment methods.
 */
class OrderPaymentInstructionsSmartcodeTest extends WPTestCase
{
    /** @var array<string, array> */
    private array $originalSettings = [];

    public function setUp(): void
    {
        parent::setUp();

        if (!GatewayManager::has('offline_payment')) {
            GatewayManager::getInstance()->register('offline_payment', new Cod());
        }
        if (!GatewayManager::has('stripe')) {
            GatewayManager::getInstance()->register('stripe', new Stripe());
        }

        foreach (['offline_payment', 'stripe'] as $slug) {
            $this->originalSettings[$slug] = GatewayManager::getInstance($slug)->settings->settings;
        }
    }

    public function tearDown(): void
    {
        foreach ($this->originalSettings as $slug => $settings) {
            GatewayManager::getInstance($slug)->settings->settings = $settings;
        }
        parent::tearDown();
    }

    public function test_offline_order_shows_thank_you_instructions_with_nested_smartcodes(): void
    {
        GatewayManager::getInstance('offline_payment')->settings->settings['thank_you_page_instructions'] =
            '<p>Pay to 12-3456-7890123-00, reference {{order.invoice_no}}</p>';

        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'invoice_no'     => 'INV-5602',
        ]);

        $this->assertSame(
            '<p>Pay to 12-3456-7890123-00, reference INV-5602</p>',
            ShortcodeTemplateBuilder::make('{{order.payment_instructions}}', ['order' => $order]),
            'offline order must render the thank-you instructions with nested smartcodes resolved'
        );
    }

    public function test_online_gateway_order_shows_nothing(): void
    {
        GatewayManager::getInstance('stripe')->settings->settings['thank_you_page_instructions'] = '<p>Stripe thanks</p>';
        GatewayManager::getInstance('offline_payment')->settings->settings['thank_you_page_instructions'] = '<p>Bank details</p>';

        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_method' => 'stripe',
        ]);

        $this->assertSame(
            '',
            ShortcodeTemplateBuilder::make('{{order.payment_instructions}}', ['order' => $order]),
            'a card (stripe) order must not show any payment instructions'
        );
    }

    public function test_blank_instructions_render_empty(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);

        foreach (['', '<p>&nbsp;</p>', "<p>\xC2\xA0</p>", '<p> </p>'] as $blank) {
            GatewayManager::getInstance('offline_payment')->settings->settings['thank_you_page_instructions'] = $blank;
            $this->assertSame(
                '',
                ShortcodeTemplateBuilder::make('{{order.payment_instructions}}', ['order' => $order]),
                'blank instructions must render empty: ' . var_export($blank, true)
            );
        }
    }

    public function test_image_only_instructions_are_shown(): void
    {
        GatewayManager::getInstance('offline_payment')->settings->settings['thank_you_page_instructions'] =
            '<p><img src="https://example.com/bank-qr.png" alt="" /></p>';

        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);

        $this->assertStringContainsString(
            '<img src="https://example.com/bank-qr.png"',
            ShortcodeTemplateBuilder::make('{{order.payment_instructions}}', ['order' => $order]),
            'instructions holding only an image (bank QR code) must still render'
        );
    }

    public function test_instructions_that_reference_themselves_do_not_recurse(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);

        foreach (['{{order.payment_instructions}}', '{{ order.payment_instructions }}', '{{order.payment_instructions|}}', '##order.payment_instructions##'] as $selfReference) {
            GatewayManager::getInstance('offline_payment')->settings->settings['thank_you_page_instructions'] =
                '<p>Bank details' . $selfReference . '</p>';

            $this->assertSame(
                '<p>Bank details</p>',
                ShortcodeTemplateBuilder::make('{{order.payment_instructions}}', ['order' => $order]),
                'a self-referencing smartcode inside the instructions must be dropped: ' . $selfReference
            );
        }
    }
}
