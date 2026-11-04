<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Looking up countries and states (admin helpers)" section, items 32-34.
 */
class ZoneLookupHelpersCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [32]
     *   "Admin can request the list of states for a known country and receives that country's state list."
     */
    public function adminCanRequestStatesForKnownCountryAndReceivesStateList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->get('/fluent-cart/v2/shipping/zone/states', ['country_code' => 'US']);

        $I->seeResponseCodeIs(200);
        Assert::assertSame('US', (string) ($body['data']['country_code'] ?? ''), 'response must echo the queried country code');
        $states = (array) ($body['data']['states'] ?? []);
        Assert::assertNotEmpty($states, 'known country US must return a non-empty state list');
    }

    /**
     * Spec: Cases/Functional/shipping.md [33]
     *   "Requesting states with no country code returns an empty result rather than a server error."
     */
    public function requestingStatesWithoutCountryCodeReturnsEmptyResult(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/shipping/zone/states');

        Assert::assertSame(200, $status, 'missing country_code must NOT 500 — it must return a clean response. Body: ' . wp_json_encode($body));
        Assert::assertSame('', (string) ($body['data']['country_code'] ?? 'X'), 'country_code must be empty when none supplied');
        Assert::assertSame([], (array) ($body['data']['states'] ?? null), 'states list must be empty when no country code supplied');
    }

    /**
     * Spec: Cases/Functional/shipping.md [34]
     *   "Admin can request the full list of countries grouped by continent, and continents with no countries are omitted."
     */
    public function adminCanRequestCountriesGroupedByContinentExcludingEmptyContinents(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->get('/fluent-cart/v2/shipping/zone/countries');

        $I->seeResponseCodeIs(200);
        $continents = (array) ($body['continents'] ?? []);
        Assert::assertNotEmpty($continents, 'continent list must not be empty');

        foreach ($continents as $cont) {
            Assert::assertArrayHasKey('code',      (array) $cont, 'each continent must include a code key. Continent: ' . wp_json_encode($cont));
            Assert::assertArrayHasKey('name',      (array) $cont, 'each continent must include a name key. Continent: ' . wp_json_encode($cont));
            Assert::assertArrayHasKey('countries', (array) $cont, 'each continent must include a countries key. Continent: ' . wp_json_encode($cont));
            Assert::assertNotEmpty(
                (array) ($cont['countries'] ?? []),
                'continents with no countries must be omitted from the grouped response; got an empty countries list under continent ' . (string) ($cont['code'] ?? '?')
            );
        }
    }
}
