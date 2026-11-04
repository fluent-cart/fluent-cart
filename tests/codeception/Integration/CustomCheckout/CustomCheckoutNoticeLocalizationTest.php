<?php

namespace Tests\Integration\CustomCheckout;

use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Ticket #3573: the custom-checkout "You are making payment for your order
 * (#...)" cart notice was hardcoded as string concatenation, so gettext
 * (and tools like Loco Translate) could not discover or translate it.
 */
class CustomCheckoutNoticeLocalizationTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
    }

    private function getSource(): string
    {
        return file_get_contents(
            dirname(__DIR__, 4) . '/app/Hooks/Handlers/CustomCheckout/CustomCheckout.php'
        );
    }

    /**
     * Pins the exact wiring: the 'content' entry must be
     * sprintf(__('...%1$s...', 'fluent-cart'), $order->uuid), not just a
     * loose __() call sitting elsewhere in the file, and not concatenation.
     */
    public function test_notice_source_uses_translation_function_not_concatenation(): void
    {
        $source = $this->getSource();

        $this->assertStringNotContainsString(
            "'You are making payment for your order (#' .",
            $source,
            'The notice must not be built via raw string concatenation.'
        );

        $this->assertMatchesRegularExpression(
            "/'content'\\s*=>\\s*sprintf\\(\\s*"
                . "(?:\\/\\*.*?\\*\\/\\s*)?"
                . "__\\(\\s*'[^']+'\\s*,\\s*'fluent-cart'\\s*\\)\\s*,\\s*"
                . "\\\$order->uuid\\s*\\)/s",
            $source,
            "The 'content' entry must be sprintf(__('...', 'fluent-cart'), \$order->uuid)."
        );
    }

    /**
     * Extracts the literal msgid actually used by CustomCheckout (rather than
     * re-typing it in the test, which could silently drift from production)
     * and proves that exact string is (a) resolved under the fluent-cart
     * gettext domain and (b) still carries the order uuid via %1$s after
     * translation.
     */
    public function test_notice_is_translatable_under_fluent_cart_domain_and_includes_order_uuid(): void
    {
        $source = $this->getSource();

        $matched = preg_match(
            "/'content'\\s*=>\\s*sprintf\\(\\s*"
                . "(?:\\/\\*.*?\\*\\/\\s*)?"
                . "__\\(\\s*'([^']+)'\\s*,\\s*'fluent-cart'\\s*\\)\\s*,\\s*"
                . "\\\$order->uuid\\s*\\)/s",
            $source,
            $captured
        );
        $this->assertSame(1, $matched, 'Could not extract the notice msgid from CustomCheckout.php; source shape changed.');

        $msgid = $captured[1];
        $this->assertStringContainsString('%1$s', $msgid, 'The extracted msgid must still carry the %1$s order-uuid placeholder.');

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
        ]);

        $this->assertNotEmpty($order->uuid, 'Order must have a uuid to build the notice from.');

        $seenDomain = null;
        $translateOverride = function ($translation, $text, $domain) use (&$seenDomain) {
            $seenDomain = $domain;
            return 'TRANSLATED: ' . $text;
        };
        add_filter('gettext_fluent-cart', $translateOverride, 10, 3);

        $content = sprintf(__($msgid, 'fluent-cart'), $order->uuid);

        remove_filter('gettext_fluent-cart', $translateOverride, 10);

        $this->assertSame('fluent-cart', $seenDomain, 'gettext must resolve the notice under the fluent-cart text domain.');
        $this->assertStringStartsWith('TRANSLATED: ', $content, 'The gettext_fluent-cart filter must be able to intercept and translate the notice.');
        $this->assertStringContainsString($order->uuid, $content, 'The rendered notice must still include the order uuid.');
    }
}
