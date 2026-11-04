<?php

namespace Tests\Integration\Services;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Renderer\Receipt\ReceiptRenderer;
use FluentCart\App\Services\Renderer\Receipt\ThankYouRender;
use FluentCart\App\Services\ShortCodeParser\SmartCodeParser;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTaxRateFactory;

/**
 * Store Settings → Business Details (company name, registration ID, tax ID,
 * VAT ID) must reach the receipt, thank-you page and order email, not only
 * the PDF shortcodes.
 */
class StoreBusinessInfoDisplayTest extends DatabaseTestCase
{
    private $previousStore;

    public function setUp(): void
    {
        parent::setUp();
        $this->previousStore = get_option('fluent_cart_store_settings', []);
        update_option('fluent_cart_store_settings', array_merge((array) $this->previousStore, [
            'store_name'            => 'Brand Shop',
            'store_country'         => 'US',
            'company_name'          => '',
            'legal_registration_id' => '',
            'seller_vat_id'         => '',
            'seller_tax_id'         => '',
        ]));
        StoreSettings::clearCache();
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/tax/country_business_id_labels');
        update_option('fluent_cart_store_settings', $this->previousStore);
        StoreSettings::clearCache();
        parent::tearDown();
    }

    public function test_the_receipt_shows_seller_ids_from_the_order_snapshot(): void
    {
        $order = $this->makeOrder([
            'company_name'          => 'Legal Co s.r.o.',
            'legal_registration_id' => '35607131',
            'seller_vat_id'         => '',
            'seller_tax_id'         => '2020971502',
        ]);

        $html = $this->renderReceipt($order);

        $this->assertStringContainsString('Legal Co s.r.o.', $html);
        $this->assertStringContainsString('Reg. ID: 35607131', $html);
        $this->assertStringContainsString('Tax ID: 2020971502', $html);
        $this->assertStringNotContainsString('VAT ID:', $html);
    }

    public function test_the_receipt_falls_back_to_the_seller_vat_id_without_a_tax_rate_vat(): void
    {
        $order = $this->makeOrder(['seller_vat_id' => 'SK2020971502']);

        $html = $this->renderStoreAddress($order);

        $this->assertStringContainsString('VAT ID: SK2020971502', $html);
    }

    public function test_the_receipt_prints_the_tax_rate_vat_once_and_not_the_seller_vat_id(): void
    {
        $order = $this->makeOrder(['seller_vat_id' => 'SK2020971502']);
        (new OrderTaxRateFactory())->create([
            'order_id' => $order->id,
            'meta'     => ['store_vat_number' => 'SK2020971502', 'tax_country' => 'SK'],
        ]);
        $order = Order::query()->with(['order_items', 'orderTaxRates'])->find($order->id);

        $html = $this->renderStoreAddress($order);

        $this->assertSame(1, substr_count($html, 'SK2020971502'));
        $this->assertStringNotContainsString('VAT ID:', $html);
    }

    public function test_an_order_without_a_snapshot_uses_live_settings(): void
    {
        (new StoreSettings())->save([
            'company_name'          => 'Live Legal Co',
            'legal_registration_id' => 'LIVE-REG-1',
            'seller_tax_id'         => 'LIVE-TAX-1',
        ]);
        $order = $this->makeOrder(null);

        $html = $this->renderReceipt($order);

        $this->assertStringContainsString('Live Legal Co', $html);
        $this->assertStringContainsString('Reg. ID: LIVE-REG-1', $html);
        $this->assertStringContainsString('Tax ID: LIVE-TAX-1', $html);
    }

    public function test_the_thank_you_page_shows_seller_ids(): void
    {
        $order = $this->makeOrder([
            'legal_registration_id' => '35607131',
            'seller_tax_id'         => '2020971502',
        ]);

        ob_start();
        (new ThankYouRender(['order' => $order]))->render(true);
        $html = html_entity_decode((string) ob_get_clean());

        $this->assertStringContainsString('Reg. ID: 35607131', $html);
        $this->assertStringContainsString('Tax ID: 2020971502', $html);
    }

    public function test_the_order_email_header_shows_seller_ids(): void
    {
        $order = $this->makeOrder([
            'legal_registration_id' => '35607131',
            'seller_tax_id'         => '2020971502',
        ]);

        $html = html_entity_decode((string) App::make('view')->make('emails.parts.order_header', ['order' => $order]));

        $this->assertStringContainsString('Reg. ID: 35607131', $html);
        $this->assertStringContainsString('Tax ID: 2020971502', $html);
    }

    public function test_store_shortcodes_read_the_snapshot_before_live_settings(): void
    {
        (new StoreSettings())->save(['legal_registration_id' => 'LIVE-REG-1']);
        $order = $this->makeOrder(['legal_registration_id' => '35607131', 'seller_tax_id' => '']);

        $html = SmartCodeParser::make()->parseTemplate(
            '[{{order.store_legal_registration_display}}][{{order.store_tax_display}}]',
            ['order' => $order]
        );

        $this->assertSame('[Reg. ID: 35607131][]', html_entity_decode($html));
    }

    public function test_a_slovak_store_uses_ico_dic_and_ic_dph_labels(): void
    {
        $this->setStoreCountry('SK');
        $order = $this->makeOrder([
            'legal_registration_id' => '35607131',
            'seller_tax_id'         => '2020971502',
            'seller_vat_id'         => 'SK2020971502',
        ]);

        $html = $this->renderReceipt($order);

        $this->assertStringContainsString('IČO: 35607131', $html);
        $this->assertStringContainsString('DIČ: 2020971502', $html);
        $this->assertStringContainsString('IČ DPH: SK2020971502', $html);
        $this->assertStringNotContainsString('Reg. ID:', $html);
        $this->assertStringNotContainsString('Tax ID:', $html);
    }

    public function test_slovak_store_shortcodes_use_the_same_labels_as_the_receipt(): void
    {
        $this->setStoreCountry('SK');
        $order = $this->makeOrder([
            'legal_registration_id' => '35607131',
            'seller_tax_id'         => '2020971502',
            'seller_vat_id'         => 'SK2020971502',
        ]);

        $html = SmartCodeParser::make()->parseTemplate(
            '[{{order.store_legal_registration_display}}][{{order.store_tax_display}}][{{order.store_seller_vat_display}}]',
            ['order' => $order]
        );

        $this->assertSame('[IČO: 35607131][DIČ: 2020971502][IČ DPH: SK2020971502]', html_entity_decode($html));
    }

    public function test_a_czech_store_uses_ico_and_dic_labels(): void
    {
        $this->setStoreCountry('CZ');
        $order = $this->makeOrder([
            'legal_registration_id' => '27082440',
            'seller_tax_id'         => 'CZ27082440',
        ]);

        $html = $this->renderReceipt($order);

        $this->assertStringContainsString('IČO: 27082440', $html);
        $this->assertStringContainsString('DIČ: CZ27082440', $html);
    }

    public function test_a_german_store_uses_the_ust_idnr_label(): void
    {
        $this->setStoreCountry('DE');
        $order = $this->makeOrder(['seller_tax_id' => '143/123/45678', 'seller_vat_id' => 'DE123456789']);

        $html = $this->renderStoreAddress($order);

        $this->assertStringContainsString('St.-Nr.: 143/123/45678', $html);
        $this->assertStringContainsString('USt-IdNr.: DE123456789', $html);
    }

    public function test_a_us_store_keeps_the_generic_labels(): void
    {
        $order = $this->makeOrder([
            'legal_registration_id' => '35607131',
            'seller_tax_id'         => '2020971502',
        ]);

        $html = $this->renderReceipt($order);

        $this->assertStringContainsString('Reg. ID: 35607131', $html);
        $this->assertStringContainsString('Tax ID: 2020971502', $html);
        $this->assertStringNotContainsString('IČO', $html);
    }

    // A store that later changes country must not relabel old orders' IDs.
    public function test_labels_follow_the_country_snapshotted_on_the_order(): void
    {
        $order = $this->makeOrder([
            'store_country'         => 'SK',
            'legal_registration_id' => '35607131',
            'seller_tax_id'         => '2020971502',
        ]);

        $receipt = $this->renderReceipt($order);
        $shortcode = SmartCodeParser::make()->parseTemplate(
            '[{{order.store_legal_registration_display}}][{{order.store_tax_display}}]',
            ['order' => $order]
        );

        $this->assertStringContainsString('IČO: 35607131', $receipt);
        $this->assertStringNotContainsString('Reg. ID: 35607131', $receipt);
        $this->assertSame('[IČO: 35607131][DIČ: 2020971502]', html_entity_decode($shortcode));
    }

    public function test_checkout_snapshots_the_store_country(): void
    {
        $this->setStoreCountry('SK');
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $cart = (object) ['checkout_data' => []];

        (new \FluentCart\App\Modules\Tax\TaxModule())->storeBusinessInfoOnOrder([
            'cart'         => $cart,
            'order'        => $order,
            'request_data' => [],
        ]);

        $this->assertSame('SK', $order->getMeta('store_business_info')['store_country']);
        $this->assertSame('SK', $order->getStoreBusinessInfo()['store_country']);
    }

    public function test_the_country_labels_filter_overrides_the_receipt_and_the_shortcodes(): void
    {
        $this->setStoreCountry('SK');
        add_filter('fluent_cart/tax/country_business_id_labels', function ($countries) {
            $countries['SK']['legal_registration_id'] = 'Company ID: %1$s';
            return $countries;
        });
        $order = $this->makeOrder([
            'legal_registration_id' => '35607131',
            'seller_tax_id'         => '2020971502',
        ]);

        $receipt = $this->renderReceipt($order);
        $shortcode = SmartCodeParser::make()->parseTemplate(
            '[{{order.store_legal_registration_display}}][{{order.store_tax_display}}]',
            ['order' => $order]
        );

        $this->assertStringContainsString('Company ID: 35607131', $receipt);
        $this->assertStringContainsString('DIČ: 2020971502', $receipt);
        $this->assertSame('[Company ID: 35607131][DIČ: 2020971502]', html_entity_decode($shortcode));
    }

    // A store in a country FluentCart doesn't list can add its own labels; unset keys stay generic.
    public function test_the_country_labels_filter_adds_a_new_country(): void
    {
        $this->setStoreCountry('PL');
        add_filter('fluent_cart/tax/country_business_id_labels', function ($countries) {
            $countries['PL'] = ['seller_tax_id' => 'NIP: %1$s', 'unknown_key' => 'X: %1$s'];
            return $countries;
        });
        $order = $this->makeOrder([
            'legal_registration_id' => '012345678',
            'seller_tax_id'         => '5260250995',
        ]);

        $receipt = $this->renderReceipt($order);

        $this->assertStringContainsString('NIP: 5260250995', $receipt);
        $this->assertStringContainsString('Reg. ID: 012345678', $receipt);
        $this->assertStringNotContainsString('X: ', $receipt);
    }

    // The PDF FROM column heading: legal company name, or the store name when none is set.
    public function test_the_store_seller_name_shortcode_prefers_the_company_name(): void
    {
        $withCompany = $this->makeOrder(['company_name' => 'Legal Co s.r.o.']);
        $withoutCompany = $this->makeOrder(['company_name' => '']);

        $parse = function (Order $order) {
            return html_entity_decode(SmartCodeParser::make()->parseTemplate('{{order.store_seller_name}}', ['order' => $order]));
        };

        $this->assertSame('Legal Co s.r.o.', $parse($withCompany));
        $this->assertSame('Brand Shop', $parse($withoutCompany));
    }

    private function setStoreCountry(string $country): void
    {
        (new StoreSettings())->save(['store_country' => $country]);
        StoreSettings::clearCache();
    }

    private function makeOrder($snapshot): Order
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'subtotal'     => 4000,
            'total_amount' => 4000,
        ]);
        (new OrderItemFactory())->create($order->id, ['unit_price' => 4000, 'subtotal' => 4000, 'line_total' => 4000]);

        if ($snapshot !== null) {
            $order->updateMeta('store_business_info', $snapshot);
        }

        return Order::query()->with(['order_items', 'orderTaxRates'])->find($order->id);
    }

    private function renderReceipt(Order $order): string
    {
        ob_start();
        (new ReceiptRenderer(['order' => $order]))->render();
        return html_entity_decode((string) ob_get_clean());
    }

    private function renderStoreAddress(Order $order): string
    {
        ob_start();
        (new ReceiptRenderer(['order' => $order]))->renderStoreAddress(true);
        return html_entity_decode((string) ob_get_clean());
    }
}
