<?php

namespace Tests\Integration\Services\Report;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\Report\DashBoardReportService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * The dashboard Order Heat Map matched map regions by the translated country
 * name, so a Dutch admin saw "Nederland: 6" in the bar chart and 0 on the map
 * (the GeoJSON region is "Netherlands"). Each row must carry a locale-stable
 * ISO-2 `country_code` for map matching; `name` stays the translated label.
 */
class CountryHeatMapTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderAddressFactory $addressFactory;

    public function setUp(): void
    {
        parent::setUp();

        // getCountryHeatMap() aggregates the whole table — start from empty.
        \FluentCart\App\Models\Order::query()->delete();
        \FluentCart\App\Models\OrderAddress::query()->delete();

        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
        $this->addressFactory  = new OrderAddressFactory();

        $this->resetCountryCache();
    }

    public function tearDown(): void
    {
        remove_all_filters('gettext');
        $this->resetCountryCache();

        parent::tearDown();
    }

    private function resetCountryCache(): void
    {
        $property = new \ReflectionProperty(LocalizationManager::class, 'countries');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function ordersFrom(string $country, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $order = $this->orderFactory->create([
                'customer_id'    => $this->customerFactory->create()->id,
                'type'           => Status::ORDER_TYPE_PAYMENT,
                'payment_status' => Status::PAYMENT_PAID,
                'currency'       => 'USD',
                'total_amount'   => Helper::toCent(10),
                'total_paid'     => Helper::toCent(10),
            ]);
            $this->addressFactory->create((int) $order->id, ['country' => $country]);
        }
    }

    private function rowsByCode(): array
    {
        $rows = DashBoardReportService::make()->getCountryHeatMap()['countryHeatMap'];

        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string) ($row['country_code'] ?? '')] = $row;
        }

        return $byCode;
    }

    public function test_rows_carry_iso2_country_code_alongside_display_name(): void
    {
        $this->ordersFrom('NL', 6);

        $rows = $this->rowsByCode();

        $this->assertArrayHasKey('NL', $rows, 'Heat map rows must be keyed for the map by ISO-2 country_code');
        $this->assertSame('Netherlands', $rows['NL']['name']);
        $this->assertSame(6, $rows['NL']['value']);
    }

    public function test_translated_locale_keeps_stable_country_code(): void
    {
        $this->ordersFrom('NL', 6);

        add_filter('gettext', function ($translation, $text, $domain) {
            return ($domain === 'fluent-cart' && $text === 'Netherlands') ? 'Nederland' : $translation;
        }, 10, 3);
        $this->resetCountryCache();

        $rows = $this->rowsByCode();

        $this->assertArrayHasKey('NL', $rows, 'A translated locale must not change the map matching key');
        $this->assertSame('Nederland', $rows['NL']['name'], 'name stays the translated display label');
        $this->assertSame(6, $rows['NL']['value']);
    }

    public function test_unknown_country_stays_uncategorized_without_a_country_code(): void
    {
        $this->ordersFrom('', 2);
        $this->ordersFrom('NL', 1);

        $rows = DashBoardReportService::make()->getCountryHeatMap()['countryHeatMap'];
        $uncategorized = array_values(array_filter($rows, function ($row) {
            return $row['name'] === 'Uncategorized';
        }));

        $this->assertCount(1, $uncategorized, 'Uncategorized stays in the payload for the bar chart');
        $this->assertSame(2, $uncategorized[0]['value']);
        $this->assertNull($uncategorized[0]['country_code'], 'Uncategorized has no country_code, so the map skips it');
    }
}
