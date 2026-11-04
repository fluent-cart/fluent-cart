<?php

namespace Tests\Functional\Frontend\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Public shipping lookup (storefront)" section, items 92-101.
 *
 * Routes (PublicPolicy, no auth):
 *   GET /fluent-cart/v2/checkout/get-available-shipping-methods
 *   GET /fluent-cart/v2/checkout/get-shipping-methods-list-view
 *   GET /fluent-cart/v2/checkout/get-country-info
 */
class PublicShippingLookupCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [92]
     *   "A logged-out shopper can request the list of available shipping methods for a country and receives the methods that apply."
     */
    public function loggedOutShopperCanRequestAvailableShippingMethodsForCountry(FunctionalTester $I): void
    {
        $I->logout();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone92', 'region' => 'US', 'shipping_class_id' => null]);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'MethodFor92',
            'type'       => 'flat_rate',
            'is_enabled' => 1,
            'states'     => [],
        ]);

        [, $body] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'US']);

        Assert::assertSame('US', (string) ($body['country_code'] ?? ''), 'response must echo back the queried country code');
        $methods = (array) ($body['available_shipping_methods'] ?? []);
        $ids = array_map('intval', array_column($methods, 'id'));
        Assert::assertContains((int) $method->id, $ids, 'public lookup must return the seeded enabled method for US');
    }

    /**
     * Spec: Cases/Functional/shipping.md [93]
     *   "Requesting available shipping methods with no country code and no timezone returns a clear \"country code is required\" error."
     */
    public function requestingMethodsWithoutCountryAndTimezoneReturnsCountryRequiredError(FunctionalTester $I): void
    {
        $I->logout();

        [, $body] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods');

        Assert::assertSame(false, (bool) ($body['status'] ?? true), 'missing country must return status=false. Body: ' . wp_json_encode($body));
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsStringIgnoringCase('country code', $message, 'error message must mention "country code". Body: ' . wp_json_encode($body));
    }

    /**
     * Spec: Cases/Functional/shipping.md [94]
     *   "Disabled shipping methods are never returned by the public lookup."
     */
    public function disabledShippingMethodsAreNeverReturnedByPublicLookup(FunctionalTester $I): void
    {
        $I->logout();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone94', 'region' => 'US', 'shipping_class_id' => null]);
        $enabledMethod  = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'Enabled94',
            'is_enabled' => 1,
            'states'     => [],
        ]);
        $disabledMethod = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'Disabled94',
            'is_enabled' => 0,
            'states'     => [],
        ]);

        [, $body] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'US']);

        $methods = (array) ($body['available_shipping_methods'] ?? []);
        $ids = array_map('intval', array_column($methods, 'id'));
        Assert::assertContains((int) $enabledMethod->id, $ids, 'public lookup must include the enabled method');
        Assert::assertNotContains((int) $disabledMethod->id, $ids, 'public lookup must NEVER include a disabled method');
    }

    /**
     * Spec: Cases/Functional/shipping.md [95]
     *   "A shipping method whose state list is non-empty is returned only when the shopper provides a matching state."
     */
    public function methodWithStatesIsReturnedOnlyWhenShopperProvidesMatchingState(FunctionalTester $I): void
    {
        $I->logout();
        $zone = (new ShippingZoneFactory())->create(['name' => 'Zone95', 'region' => 'US', 'shipping_class_id' => null]);
        $stateScopedMethod = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'CAOnly95',
            'is_enabled' => 1,
            'states'     => ['CA'],
        ]);
        $globalMethod = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'AnyState95',
            'is_enabled' => 1,
            'states'     => [],
        ]);

        [, $matchingBody] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'US', 'state' => 'CA']);
        $matchingIds = array_map('intval', array_column((array) ($matchingBody['available_shipping_methods'] ?? []), 'id'));
        Assert::assertContains((int) $stateScopedMethod->id, $matchingIds, 'state-scoped method must be returned when shopper provides matching state');

        [, $nonMatchingBody] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'US', 'state' => 'NY']);
        $nonMatchingIds = array_map('intval', array_column((array) ($nonMatchingBody['available_shipping_methods'] ?? []), 'id'));
        Assert::assertNotContains((int) $stateScopedMethod->id, $nonMatchingIds, 'state-scoped method must NOT be returned when shopper provides non-matching state');
        Assert::assertContains((int) $globalMethod->id, $nonMatchingIds, 'state-empty method must still be returned regardless of shopper state');
    }

    /**
     * Spec: Cases/Functional/shipping.md [96]
     *   "A shipping method belonging to an \"include these countries\" selection zone is returned only when the shopper's country is on the include list."
     */
    public function methodInIncludeSelectionZoneIsReturnedOnlyWhenCountryOnIncludeList(FunctionalTester $I): void
    {
        $I->logout();
        $zone = (new ShippingZoneFactory())->create([
            'name'              => 'IncludeSelZone96',
            'region'            => 'selection',
            'shipping_class_id' => null,
            'meta'              => ['countries' => ['US', 'CA'], 'selection_type' => 'included'],
        ]);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'IncMethod96',
            'is_enabled' => 1,
            'states'     => [],
        ]);

        [, $inBody] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'US']);
        $inIds = array_map('intval', array_column((array) ($inBody['available_shipping_methods'] ?? []), 'id'));
        Assert::assertContains((int) $method->id, $inIds, 'method must be returned when shopper country is on the include list');

        [, $outBody] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'GB']);
        $outIds = array_map('intval', array_column((array) ($outBody['available_shipping_methods'] ?? []), 'id'));
        Assert::assertNotContains((int) $method->id, $outIds, 'method must NOT be returned when shopper country is NOT on the include list');
    }

    /**
     * Spec: Cases/Functional/shipping.md [97]
     *   "A shipping method belonging to an \"exclude these countries\" selection zone is returned to every country except those on the exclude list."
     */
    public function methodInExcludeSelectionZoneIsReturnedToEveryCountryExceptExcluded(FunctionalTester $I): void
    {
        $I->logout();
        $zone = (new ShippingZoneFactory())->create([
            'name'              => 'ExcludeSelZone97',
            'region'            => 'selection',
            'shipping_class_id' => null,
            'meta'              => ['countries' => ['US'], 'selection_type' => 'excluded'],
        ]);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'ExcMethod97',
            'is_enabled' => 1,
            'states'     => [],
        ]);

        [, $excludedBody] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'US']);
        $excludedIds = array_map('intval', array_column((array) ($excludedBody['available_shipping_methods'] ?? []), 'id'));
        Assert::assertNotContains((int) $method->id, $excludedIds, 'method must NOT be returned to a country on the exclude list');

        [, $includedBody] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'GB']);
        $includedIds = array_map('intval', array_column((array) ($includedBody['available_shipping_methods'] ?? []), 'id'));
        Assert::assertContains((int) $method->id, $includedIds, 'method must be returned to a country NOT on the exclude list');
    }

    /**
     * Spec: Cases/Functional/shipping.md [99]
     *   "When no shipping methods apply for the shopper's country, the public lookup returns a clear \"no shipping methods available\" message instead of a server error."
     */
    public function whenNoMethodsApplyPublicLookupReturnsNoMethodsAvailableMessage(FunctionalTester $I): void
    {
        $I->logout();
        // No shipping methods seeded.

        [, $body] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'ZA']);

        Assert::assertSame(false, (bool) ($body['status'] ?? true), 'empty-results lookup must report status=false. Body: ' . wp_json_encode($body));
        $view = (string) ($body['view'] ?? '');
        Assert::assertStringContainsStringIgnoringCase('no shipping methods', $view, 'view payload must include the "no shipping methods" message. Body: ' . wp_json_encode($body));
        Assert::assertSame('ZA', (string) ($body['country_code'] ?? ''), 'empty-results lookup must still echo the queried country');
    }

    /**
     * Spec: Cases/Functional/shipping.md [100]
     *   "The public lookup returns the rendered shipping-method list HTML when requested in list-view mode."
     */
    public function publicLookupReturnsRenderedShippingMethodListHtmlWhenListViewMode(FunctionalTester $I): void
    {
        $I->logout();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone100', 'region' => 'US', 'shipping_class_id' => null]);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'HtmlListMethod100',
            'is_enabled' => 1,
            'states'     => [],
        ]);

        [, $body] = $I->get('/fluent-cart/v2/checkout/get-shipping-methods-list-view', ['country_code' => 'US']);

        Assert::assertSame(true, (bool) ($body['status'] ?? false), 'list-view response must report status=true when methods exist. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('view', (array) $body, 'list-view response must include a rendered view payload');
        Assert::assertSame('US', (string) ($body['country_code'] ?? ''), 'list-view response must echo the queried country');
    }

    /**
     * Spec: Cases/Functional/shipping.md [101]
     *   "The public country-info lookup accepts either a country code or a timezone and returns the matching state list and address fields."
     */
    public function publicCountryInfoLookupAcceptsCountryCodeOrTimezone(FunctionalTester $I): void
    {
        $I->logout();

        [, $byCode] = $I->get('/fluent-cart/v2/checkout/get-country-info', ['country_code' => 'US']);
        Assert::assertSame('US', (string) ($byCode['country_code'] ?? ''), 'country_code lookup must echo the supplied code');
        Assert::assertArrayHasKey('states',         (array) $byCode, 'country_code response must include states');
        Assert::assertArrayHasKey('address_locale', (array) $byCode, 'country_code response must include address_locale');

        [, $byTz] = $I->get('/fluent-cart/v2/checkout/get-country-info', ['timezone' => 'America/New_York']);
        Assert::assertNotEmpty($byTz['country_code'] ?? '', 'timezone lookup must derive a non-empty country code');
        Assert::assertArrayHasKey('states',         (array) $byTz, 'timezone response must include states');
        Assert::assertArrayHasKey('address_locale', (array) $byTz, 'timezone response must include address_locale');
    }
}
