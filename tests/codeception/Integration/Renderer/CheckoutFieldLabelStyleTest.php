<?php

namespace Tests\Integration\Renderer;

use FluentCart\App\Hooks\Handlers\BlockEditors\Checkout\CheckoutBlockEditor;
use FluentCart\App\Hooks\Handlers\ShortCodes\Checkout\CheckoutPageHandler;
use FluentCart\App\Services\Renderer\AddressSelectRenderer;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use FluentCart\App\Services\Renderer\FormFieldRenderer;
use FluentCart\App\Services\Renderer\VatFieldRenderer;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;

/**
 * The checkout's "Field labels" setting: the checkout wrapper carries
 * fct-field-labels-{docked|floating|placeholder} and each grouped field wrapper
 * carries the field name in data-fct-label, which the CSS draws.
 */
class CheckoutFieldLabelStyleTest extends DatabaseTestCase
{
    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/checkout/render_cart');
        parent::tearDown();
    }

    /**
     * @dataProvider labelStyleProvider
     */
    public function test_label_style_is_limited_to_the_three_known_styles($given, $expected): void
    {
        $this->assertSame($expected, CheckoutRenderer::resolveFieldLabelStyle($given), 'Unexpected style for ' . var_export($given, true));
    }

    public function labelStyleProvider(): array
    {
        return [
            'docked'        => ['docked', 'docked'],
            'floating'      => ['floating', 'floating'],
            'placeholder'   => ['placeholder', 'placeholder'],
            'empty'         => ['', 'docked'],
            'null'          => [null, 'docked'],
            'unknown'       => ['outlined', 'docked'],
            'markup'        => ['floating" onmouseover="x', 'docked'],
            'wrong case'    => ['Floating', 'docked'],
        ];
    }

    public function test_checkout_wrapper_defaults_to_docked_labels(): void
    {
        $cart = (new CartFactory())->create(['cart_data' => [CartFactory::makeCartItem()]]);

        ob_start();
        (new CheckoutRenderer($cart))->wrapperStart();
        $html = ob_get_clean() . '</div>';

        $this->assertStringContainsString('fct-field-labels-docked', $html, 'A checkout with no label setting must use docked labels.');
    }

    public function test_checkout_wrapper_uses_the_configured_label_style(): void
    {
        $cart = (new CartFactory())->create(['cart_data' => [CartFactory::makeCartItem()]]);

        ob_start();
        (new CheckoutRenderer($cart, ['field_label_style' => 'floating']))->wrapperStart();
        $html = ob_get_clean() . '</div>';

        $this->assertStringContainsString('fct-field-labels-floating', $html, 'The configured style must reach the wrapper class.');
        $this->assertStringNotContainsString('fct-field-labels-docked', $html, 'Only one label style class may be present.');
    }

    public function test_text_field_wrapper_carries_its_placeholder_as_the_visible_label(): void
    {
        ob_start();
        (new FormFieldRenderer())->renderField([
            'type'        => 'email',
            'id'          => 'billing_email',
            'name'        => 'billing_email',
            'placeholder' => 'Email Address *',
            'aria-label'  => 'Email Address',
        ]);
        $html = ob_get_clean();

        $this->assertStringContainsString('data-fct-label="Email Address *"', $html, 'The wrapper must carry the placeholder text, required mark included.');
        $this->assertStringContainsString('data-fct-field="input"', $html, 'Text-like fields are marked as inputs for the label CSS.');
    }

    public function test_select_field_wrapper_carries_its_label(): void
    {
        ob_start();
        (new FormFieldRenderer())->renderField([
            'type'        => 'select',
            'id'          => 'billing_country',
            'name'        => 'billing_country',
            'placeholder' => 'Country / Region *',
            'options'     => [['name' => 'Peru', 'value' => 'PE']],
        ]);
        $html = ob_get_clean();

        $this->assertStringContainsString('data-fct-label="Country / Region *"', $html, 'Select wrappers need the label too; selects always show it.');
        $this->assertStringContainsString('data-fct-field="select"', $html, 'Select wrappers are marked so the label CSS keeps their label shown.');
    }

    public function test_field_without_placeholder_falls_back_to_its_aria_label(): void
    {
        ob_start();
        (new FormFieldRenderer())->renderField([
            'type'       => 'text',
            'id'         => 'billing_vat',
            'name'       => 'billing_vat',
            'aria-label' => 'VAT &amp; Tax Number',
        ]);
        $html = ob_get_clean();

        $this->assertStringContainsString('data-fct-label="VAT &amp; Tax Number"', $html, 'The aria-label is decoded once and escaped once, never double-encoded.');
    }

    public function test_add_new_address_form_wrappers_carry_their_labels(): void
    {
        $renderer = new AddressSelectRenderer([], [], [
            'country'   => 'required',
            'address_1' => 'required',
            'address_2' => 'optional',
            'city'      => 'required',
            'postcode'  => 'required',
            'phone'     => 'optional',
        ], 'billing');

        ob_start();
        $renderer->renderAddAddressForm();
        $html = ob_get_clean();

        $this->assertStringContainsString('id="billing_address_1_wrapper" data-fct-label="Street Address *"', $html, 'Required popup fields carry the name with a required mark.');
        $this->assertStringContainsString('id="billing_address_2_wrapper" data-fct-label="Apt, suite, unit"', $html, 'Optional popup fields carry the name without a mark.');
        $this->assertStringContainsString('id="billing_country_wrapper" data-fct-label="Country / Region *"', $html, 'The popup country select needs its label too.');
        $this->assertStringContainsString('id="billing_postcode_wrapper" data-fct-label="Postcode / ZIP *" data-fct-field="input"', $html, 'City and ZIP in the nested section are labelled as well.');
        $this->assertStringContainsString('data-fct-label="Country / Region *" data-fct-field="select"', $html, 'The popup country is marked as a select.');
        $this->assertMatchesRegularExpression('/class="fct_checkout_input_group fct_2_columns"/', $html, 'City and ZIP share a row through the generic two-column class, not a billing-only ID.');
    }

    public function test_business_detail_fields_carry_their_labels(): void
    {
        $settings = CheckoutFieldsSchema::getFieldsSettings();
        $settings['business_details']['company_name'] = ['enabled' => 'yes', 'required' => 'yes'];
        $settings['business_details']['legal_registration_id'] = ['enabled' => 'yes', 'required' => 'no'];
        fluent_cart_update_option('_fc_checkout_fields', $settings);
        CheckoutFieldsSchema::resetFieldsCache();
        $cart = (new CartFactory())->create(['cart_data' => [CartFactory::makeCartItem()]]);

        ob_start();
        (new CheckoutRenderer($cart))->renderBusinessDetailsSection(true);
        $html = ob_get_clean();
        CheckoutFieldsSchema::resetFieldsCache();

        $this->assertMatchesRegularExpression('/id="billing_company_name_wrapper"\s+data-fct-label="Company Name \*"\s+data-fct-field="input"/', $html, 'Company follows the label style, with its required mark.');
        $this->assertMatchesRegularExpression('/id="billing_legal_registration_id_wrapper"\s+data-fct-label="Legal Registration ID"\s+data-fct-field="input"/', $html, 'An optional legal ID is labelled without a mark.');
    }

    public function test_vat_field_wrapper_carries_its_label(): void
    {
        ob_start();
        (new VatFieldRenderer('BD'))->renderInner([]);
        $html = ob_get_clean();

        $this->assertMatchesRegularExpression('/id="fct_billing_tax_id_wrapper"\s+data-fct-label="VAT\/GST\/Tax Number(?: \*)?"\s+data-fct-field="input"/', $html, 'The VAT field follows the label style like the other business fields.');
    }

    /**
     * @dataProvider vatButtonStateProvider
     */
    public function test_vat_action_buttons_carry_the_field_label_under_their_own_attribute($isValid, $buttonMarker): void
    {
        update_option('fluent_cart_tax_configuration_settings', ['enable_tax' => 'yes']);

        ob_start();
        (new VatFieldRenderer('DE'))->renderInner(['tax_data' => ['valid' => $isValid, 'vat_number' => 'DE123456789']]);
        $html = ob_get_clean();

        $this->assertMatchesRegularExpression('/' . $buttonMarker . '\s+data-fct-action-label="VAT\/GST\/Tax Number(?: \*)?"/', $html, 'The docked label next to the VAT button is drawn by the button, so it needs the field name.');
        // data-fct-label would match the field-wrapper rules and draw a clipped floating label inside the button
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*\sdata-fct-label=/', $html);
    }

    public function vatButtonStateProvider(): array
    {
        return [
            'apply'  => [false, 'data-fluent-cart-checkout-page-tax-apply-btn'],
            'remove' => [true, 'data-fluent-cart-tax-remove-btn'],
        ];
    }

    public function test_shortcode_passes_its_label_style_to_the_checkout(): void
    {
        $cart = (new CartFactory())->create(['cart_data' => [CartFactory::makeCartItem()]]);
        add_filter('fluent_cart/checkout/render_cart', function () use ($cart) {
            return $cart;
        });

        ob_start();
        $returned = (new CheckoutPageHandler())->render(['field_label_style' => 'placeholder']);
        $html = ob_get_clean() . (string)$returned;

        $this->assertStringContainsString('fct-field-labels-placeholder', $html, 'The shortcode attribute must select the label style.');
    }

    public function test_block_without_inner_blocks_forwards_its_label_style_to_the_shortcode(): void
    {
        $cart = (new CartFactory())->create(['cart_data' => [CartFactory::makeCartItem()]]);
        add_filter('fluent_cart/checkout/render_cart', function () use ($cart) {
            return $cart;
        });

        $html = (new CheckoutBlockEditor())->render(['field_label_style' => 'floating']);

        $this->assertSame('[fluent_cart_checkout field_label_style="floating"]', $html, 'The legacy block path must keep the chosen style.');
    }

    public function test_block_forwards_only_known_label_styles_to_the_shortcode(): void
    {
        $cart = (new CartFactory())->create(['cart_data' => [CartFactory::makeCartItem()]]);
        add_filter('fluent_cart/checkout/render_cart', function () use ($cart) {
            return $cart;
        });

        $html = (new CheckoutBlockEditor())->render(['field_label_style' => '"] [evil']);

        $this->assertSame('[fluent_cart_checkout field_label_style="docked"]', $html, 'An unknown style must not reach the shortcode string.');
    }
}
