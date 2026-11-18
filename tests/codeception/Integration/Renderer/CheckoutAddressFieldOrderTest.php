<?php

namespace Tests\Integration\Renderer;

use FluentCart\App\App;
use FluentCart\App\Http\Controllers\CheckoutFieldsController;
use FluentCart\App\Services\Renderer\AddressSelectRenderer;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;

/**
 * Checkout Fields "Rearrange": each section's saved field order drives the
 * order of billing/shipping fields on the checkout.
 */
class CheckoutAddressFieldOrderTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->saveOrder([]);
    }

    public function tearDown(): void
    {
        $this->saveOrder([]);
        parent::tearDown();
    }

    private function saveOrder(array $order): void
    {
        foreach (['billing_address', 'shipping_address'] as $section) {
            update_option(CheckoutFieldsSchema::addressFieldOrderKey($section), $order[$section] ?? []);
        }
        CheckoutFieldsSchema::resetFieldsCache();
    }

    public function test_sanitize_keeps_only_known_keys_once(): void
    {
        $clean = CheckoutFieldsSchema::sanitizeAddressFieldOrder([
            'billing_address'  => ['postcode', 'evil<script>', 'postcode', ['nested'], 'full_name', 'city'],
            'shipping_address' => 'not-a-list',
            'basic_info'       => ['email'],
        ], CheckoutFieldsSchema::getFieldsSettings());

        $this->assertSame([
            'billing_address'  => ['postcode', 'city'],
            'shipping_address' => [],
        ], $clean, 'Unknown, duplicate, nested and other-section keys must be dropped; billing has no full_name.');
    }

    public function test_save_field_order_endpoint_saves_one_section_and_main_save_keeps_it(): void
    {
        $this->saveOrder(['billing_address' => ['city'], 'shipping_address' => ['phone']]);
        $controller = new CheckoutFieldsController();

        $response = $controller->saveFieldOrder(new FluentRequest(App::getInstance(), [
            'section' => 'billing_address',
            'order'   => ['postcode', 'bogus', 'country'],
        ], []));
        $this->assertSame(['postcode', 'country'], $response['order']);

        $controller->saveFields(new FluentRequest(App::getInstance(), [
            'settings' => ['address_field_order' => ['billing_address' => ['phone'], 'shipping_address' => []]],
        ], []));
        CheckoutFieldsSchema::resetFieldsCache();

        $this->assertSame(
            ['billing_address' => ['postcode', 'country'], 'shipping_address' => ['phone']],
            [
                'billing_address'  => CheckoutFieldsSchema::getAddressFieldOrder('billing_address'),
                'shipping_address' => CheckoutFieldsSchema::getAddressFieldOrder('shipping_address'),
            ],
            'Only the named section changes, and the main save must not overwrite the order.'
        );
    }

    public function test_order_save_does_not_rewrite_field_settings(): void
    {
        // Separate storage: an order save racing a page Save (or the other section's save) cannot undo it.
        fluent_cart_update_option('_fc_checkout_fields', ['agree_terms' => ['enabled' => 'yes', 'required' => 'no', 'text' => 'sentinel']]);
        CheckoutFieldsSchema::resetFieldsCache();
        $fieldSettings = fluent_cart_get_option('_fc_checkout_fields', [], false);

        (new CheckoutFieldsController())->saveFieldOrder(new FluentRequest(App::getInstance(), [
            'section' => 'shipping_address',
            'order'   => ['phone', 'full_name'],
        ], []));

        $this->assertSame($fieldSettings, fluent_cart_get_option('_fc_checkout_fields', [], false));
        $this->assertSame(['phone', 'full_name'], CheckoutFieldsSchema::getAddressFieldOrder('shipping_address'));
    }

    public function test_field_settings_do_not_carry_the_order(): void
    {
        $this->saveOrder(['billing_address' => ['postcode'], 'shipping_address' => []]);

        $this->assertArrayNotHasKey('address_field_order', CheckoutFieldsSchema::getFieldsSettings(), 'The page Save must never receive the order.');
        $this->assertSame(['postcode'], (new CheckoutFieldsController())->getFields()['address_field_order']['billing_address']);
    }

    public function test_save_field_order_rejects_unknown_section(): void
    {
        $response = (new CheckoutFieldsController())->saveFieldOrder(new FluentRequest(App::getInstance(), [
            'section' => 'basic_info',
            'order'   => ['email'],
        ], []));

        $this->assertSame(422, $response->get_status());
    }

    public function test_saved_order_reorders_address_fields_and_keeps_the_rest_after_it(): void
    {
        $this->saveOrder(['billing_address' => ['postcode', 'city', 'country'], 'shipping_address' => ['phone', 'full_name']]);

        $billing = array_keys(CheckoutFieldsSchema::getAddressBaseFields(['type' => 'billing'], 'admin'));
        $this->assertSame(['postcode', 'city', 'country', 'address_1', 'address_2', 'state', 'phone', 'company_name'], $billing);

        $shipping = array_keys(CheckoutFieldsSchema::getAddressBaseFields(['type' => 'shipping'], 'admin'));
        $this->assertSame(['phone', 'full_name', 'country', 'address_1', 'address_2', 'state', 'city', 'postcode', 'company_name'], $shipping);
    }

    public function test_no_saved_order_keeps_the_default_order(): void
    {
        $billing = array_keys(CheckoutFieldsSchema::getAddressBaseFields(['type' => 'billing'], 'admin'));
        $this->assertSame(['country', 'address_1', 'address_2', 'state', 'city', 'postcode', 'phone', 'company_name'], $billing);
    }

    public function test_renderer_keeps_the_chosen_order_and_merges_adjacent_city_postcode_in_place(): void
    {
        $fields = array_fill_keys(['phone', 'postcode', 'city', 'country', 'address_1'], ['type' => 'text']);
        $renderer = new CheckoutRenderer((new CartFactory())->create(['cart_data' => [CartFactory::makeCartItem()]]));
        $rearrange = new \ReflectionMethod($renderer, 'maybeRearrangeAddressFields');
        $rearrange->setAccessible(true);

        $this->saveOrder(['billing_address' => ['phone', 'postcode', 'city'], 'shipping_address' => []]);
        $result = $rearrange->invoke($renderer, $fields, 'billing');
        $this->assertSame(['phone', 'city_postal', 'country', 'address_1'], array_keys($result), 'Phone must stay first and the merged row must sit where the pair was.');
        $this->assertSame(['postcode', 'city'], array_keys($result['city_postal']['fields']));

        // Shipping has no saved order, so the old rearrange still applies.
        $result = $rearrange->invoke($renderer, $fields, 'shipping');
        $this->assertSame(['country', 'address_1', 'city_postal', 'phone'], array_keys($result));
    }

    private function modalOrder(string $type): array
    {
        $required = array_fill_keys(['phone', 'country', 'address_1', 'address_2', 'state', 'city', 'postcode'], 'required');
        ob_start();
        (new AddressSelectRenderer([], [], $required, $type))->renderAddAddressForm();
        preg_match_all('/id="' . $type . '_((?:phone|country|address_1|address_2|state|city|postcode)_wrapper|city_zip_section)"/', ob_get_clean(), $matches);

        return str_replace('_wrapper', '', $matches[1]);
    }

    public function test_add_address_modal_follows_the_saved_order(): void
    {
        $this->assertSame(['phone', 'country', 'address_1', 'address_2', 'state', 'city_zip_section', 'city', 'postcode'], $this->modalOrder('billing'), 'Default modal order must not change.');

        $this->saveOrder(['billing_address' => ['postcode', 'country', 'city', 'phone'], 'shipping_address' => ['postcode', 'city']]);
        $this->assertSame(['postcode', 'country', 'city', 'phone', 'address_1', 'address_2', 'state'], $this->modalOrder('billing'), 'Non-adjacent city and postcode render without the shared row.');
        $this->assertSame(['city_zip_section', 'postcode', 'city', 'phone', 'country', 'address_1', 'address_2', 'state'], $this->modalOrder('shipping'));
    }

    public function test_shipping_modal_puts_the_name_where_the_saved_order_does(): void
    {
        $this->saveOrder(['billing_address' => [], 'shipping_address' => ['country', 'full_name']]);
        $required = array_fill_keys(['country', 'address_1', 'address_2', 'state', 'city', 'postcode'], 'required');

        ob_start();
        (new AddressSelectRenderer([], [], $required, 'shipping'))->renderAddAddressForm();
        preg_match_all('/id="shipping_((?:name|first_name|country|address_1)_wrapper)"/', ob_get_clean(), $matches);

        $this->assertSame(['country', 'name', 'address_1'], array_slice(str_replace('_wrapper', '', $matches[1]), 0, 3));
    }

    public function test_add_address_modal_order_filter_still_wins(): void
    {
        $this->saveOrder(['billing_address' => ['postcode', 'city'], 'shipping_address' => []]);
        $filter = function () {
            return ['state', 'bogus', 'country'];
        };
        add_filter('fluent_cart/fields/address_modal_field_order', $filter);

        try {
            $this->assertSame(['state', 'country'], $this->modalOrder('billing'), 'Unknown keys are dropped; omitted keys are not rendered.');
        } finally {
            remove_filter('fluent_cart/fields/address_modal_field_order', $filter);
        }
    }

    public function test_renderer_leaves_city_and_postcode_apart_when_not_adjacent(): void
    {
        $fields = array_fill_keys(['postcode', 'country', 'city'], ['type' => 'text']);
        $renderer = new CheckoutRenderer((new CartFactory())->create(['cart_data' => [CartFactory::makeCartItem()]]));
        $rearrange = new \ReflectionMethod($renderer, 'maybeRearrangeAddressFields');
        $rearrange->setAccessible(true);

        $this->saveOrder(['billing_address' => ['postcode', 'country', 'city'], 'shipping_address' => []]);
        $this->assertSame(['postcode', 'country', 'city'], array_keys($rearrange->invoke($renderer, $fields, 'billing')));
    }
}
