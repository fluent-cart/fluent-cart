<?php

/**
 * Address Validation Test Suite
 *
 * Usage:
 *   WP CLI:  wp eval-file wp-content/plugins/fluent-cart/dev/test-address-validation.php
 *   Browser: Add ?fct_test_address=1 to any page (admin only)
 */

if (!defined('ABSPATH') && !defined('WP_CLI')) {
    exit("This script must be run within WordPress or WP-CLI.\n");
}

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\Framework\Support\Arr;

class AddressValidationTester
{
    private $storeCountry;
    private $originalSettings;
    private $customer;
    private $createdAddressIds = [];
    private $results = [];
    private $isHtml = false;

    public function __construct($isHtml = false)
    {
        $this->isHtml = $isHtml;
        $this->storeCountry = (new StoreSettings())->get('store_country');
        $this->originalSettings = fluent_cart_get_option('_fc_checkout_fields', []);
    }

    public function run()
    {
        $this->log("=== Address Validation Test Suite ===");
        $this->log("Store Country: " . ($this->storeCountry ?: '(not set)'));
        $this->log("");

        $this->customer = $this->getOrCreateTestCustomer();
        if (!$this->customer) {
            $this->log("FATAL: Could not find a customer. Create one first.");
            return $this->results;
        }
        $this->log("Using customer ID: " . $this->customer->id);
        $this->log("");

        // Run test groups — wrapped in try/finally to guarantee cleanup
        try {
            $this->testGroup1_AllFieldsDisabled();
            $this->testGroup2_CountryEnabled();
            $this->testGroup3_StateValidation();
            $this->testGroup4_PostcodeValidation();
            $this->testGroup5_ExtraDataStillValid();
            $this->testGroup6_CountryFilterWithStoreCountry();
            $this->testGroup7_BillingType();
            $this->testGroup8_OptionalFieldsProvided();
            $this->testGroup9_StateOptionalButProvided();
            $this->testGroup10_PostcodeCountryWithoutRules();
            $this->testGroup11_NameFallbacks();
            $this->testGroup12_MultipleFieldCombinations();
        } finally {
            $this->cleanup();
            $this->restoreSettings();
        }

        // Summary
        $this->log("");
        $this->log("=== Summary ===");
        $passed = count(array_filter($this->results, function ($r) { return $r['pass']; }));
        $failed = count($this->results) - $passed;
        $this->log("Passed: $passed | Failed: $failed | Total: " . count($this->results));

        if ($failed > 0) {
            $this->log("");
            $this->log("FAILURES:");
            foreach ($this->results as $r) {
                if (!$r['pass']) {
                    $this->log("  FAIL: {$r['name']} - {$r['reason']}");
                }
            }
        }

        return $this->results;
    }

    // ── Test Group 1: All address fields disabled ──

    private function testGroup1_AllFieldsDisabled()
    {
        $this->log("--- Group 1: All address fields disabled, first_name required ---");

        $this->applySettings([
            'shipping_address' => $this->allDisabled(),
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        // Address with first_name only → should PASS
        $id1 = $this->createAddress([
            'type' => 'shipping',
            'meta' => ['other_data' => ['first_name' => 'Alice']],
        ]);
        $this->assertAddressValid('shipping', $id1,
            '1a: Address with only first_name passes when all address fields disabled');

        // Address without first_name → should FAIL
        $id2 = $this->createAddress([
            'type' => 'shipping',
            'meta' => ['other_data' => ['company_name' => 'Acme']],
        ]);
        $this->assertAddressInvalid('shipping', $id2,
            '1b: Address without first_name fails when first_name is required');

        // Address with extra data (country, city filled) → should still PASS
        $id3 = $this->createAddress([
            'type' => 'shipping',
            'country' => $this->storeCountry ?: 'US',
            'city' => 'Dhaka',
            'meta' => ['other_data' => ['first_name' => 'Bob']],
        ]);
        $this->assertAddressValid('shipping', $id3,
            '1c: Address with extra data (country, city) still passes');

        $this->log("");
    }

    // ── Test Group 2: Country enabled + required ──

    private function testGroup2_CountryEnabled()
    {
        $this->log("--- Group 2: Country enabled + required ---");

        $settings = $this->allDisabled();
        $settings['country'] = ['required' => 'yes', 'enabled' => 'yes'];

        $this->applySettings([
            'shipping_address' => $settings,
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        // Valid country → PASS
        $id1 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'meta' => ['other_data' => ['first_name' => 'Charlie']],
        ]);
        $this->assertAddressValid('shipping', $id1,
            '2a: Address with valid country passes');

        // No country → FAIL
        $id2 = $this->createAddress([
            'type' => 'shipping',
            'meta' => ['other_data' => ['first_name' => 'Dave']],
        ]);
        $this->assertAddressInvalid('shipping', $id2,
            '2b: Address without country fails when country is required');

        // Invalid country code → FAIL
        $id3 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'ZZZZ',
            'meta' => ['other_data' => ['first_name' => 'Eve']],
        ]);
        $this->assertAddressInvalid('shipping', $id3,
            '2c: Address with invalid country code fails');

        $this->log("");
    }

    // ── Test Group 3: State validation ──

    private function testGroup3_StateValidation()
    {
        $this->log("--- Group 3: State validation ---");

        // 3a-c: State required, country disabled → no country to validate against for existing addresses
        // (we never assume store country for existing address validation)
        $settings = $this->allDisabled();
        $settings['state'] = ['required' => 'yes', 'enabled' => 'yes'];

        $this->applySettings([
            'shipping_address' => $settings,
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        $testCountry = $this->storeCountry ?: 'US';
        $localization = LocalizationManager::getInstance();
        $states = $localization->countryStates($testCountry);

        if (!empty($states)) {
            $validStateCode = array_key_first($states);

            // Address with state but no country → PASS (no country to validate state against)
            $id1 = $this->createAddress([
                'type' => 'shipping',
                'state' => $validStateCode,
                'meta' => ['other_data' => ['first_name' => 'Frank']],
            ]);
            $this->assertAddressValid('shipping', $id1,
                "3a: State ($validStateCode) with no country passes (can't validate without country)");

            // Invalid state but no country → PASS (no country = can't validate state format)
            $id2 = $this->createAddress([
                'type' => 'shipping',
                'state' => 'INVALID_STATE_XYZ',
                'meta' => ['other_data' => ['first_name' => 'Grace']],
            ]);
            $this->assertAddressValid('shipping', $id2,
                '3b: Invalid state with no country passes (no country to check against)');

            // No state when required but no country → PASS (required state can only fail when country has states)
            $id3 = $this->createAddress([
                'type' => 'shipping',
                'meta' => ['other_data' => ['first_name' => 'Hank']],
            ]);
            $this->assertAddressValid('shipping', $id3,
                '3c: Missing state with no country passes (no country = no state list to check)');

            // Address WITH country and valid state → PASS
            $id3b = $this->createAddress([
                'type' => 'shipping',
                'country' => $testCountry,
                'state' => $validStateCode,
                'meta' => ['other_data' => ['first_name' => 'Hank2']],
            ]);
            $this->assertAddressValid('shipping', $id3b,
                "3c2: Valid state with country present passes");

            // Address WITH country and invalid state → FAIL
            $id3c = $this->createAddress([
                'type' => 'shipping',
                'country' => $testCountry,
                'state' => 'INVALID_STATE_XYZ',
                'meta' => ['other_data' => ['first_name' => 'Hank3']],
            ]);
            $this->assertAddressInvalid('shipping', $id3c,
                "3c3: Invalid state with country present fails");
        } else {
            $this->log("  SKIP: Store country ($testCountry) has no states defined");
        }

        // 3d: State required + country enabled → state validated against address country
        $settings['country'] = ['required' => 'yes', 'enabled' => 'yes'];
        $this->applySettings([
            'shipping_address' => $settings,
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        $usStates = $localization->countryStates('US');
        $bdStates = $localization->countryStates('BD');

        if (!empty($usStates) && !empty($bdStates)) {
            $usStateCode = array_key_first($usStates);
            $bdStateCode = array_key_first($bdStates);

            // US state with US country → PASS
            $id4 = $this->createAddress([
                'type' => 'shipping',
                'country' => 'US',
                'state' => $usStateCode,
                'meta' => ['other_data' => ['first_name' => 'Ivy']],
            ]);
            $this->assertAddressValid('shipping', $id4,
                "3d: US state ($usStateCode) with US country passes");

            // BD state with US country → FAIL (ambiguous state code test)
            $id5 = $this->createAddress([
                'type' => 'shipping',
                'country' => 'US',
                'state' => $bdStateCode,
                'meta' => ['other_data' => ['first_name' => 'Jack']],
            ]);
            $this->assertAddressInvalid('shipping', $id5,
                "3e: BD state ($bdStateCode) with US country fails (cross-country mismatch)");
        }

        $this->log("");
    }

    // ── Test Group 4: Postcode validation ──

    private function testGroup4_PostcodeValidation()
    {
        $this->log("--- Group 4: Postcode validation ---");

        $settings = $this->allDisabled();
        $settings['postcode'] = ['required' => 'yes', 'enabled' => 'yes'];
        $settings['country'] = ['required' => 'yes', 'enabled' => 'yes'];

        $this->applySettings([
            'shipping_address' => $settings,
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        // Valid UK postcode → PASS
        $id1 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'GB',
            'postcode' => 'SW1A 1AA',
            'meta' => ['other_data' => ['first_name' => 'Kate']],
        ]);
        $this->assertAddressValid('shipping', $id1,
            '4a: Valid UK postcode passes');

        // No postcode when required → FAIL
        $id2 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'GB',
            'meta' => ['other_data' => ['first_name' => 'Leo']],
        ]);
        $this->assertAddressInvalid('shipping', $id2,
            '4b: Missing postcode fails when required');

        // Invalid UK postcode format → FAIL
        $id3 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'GB',
            'postcode' => '12345',
            'meta' => ['other_data' => ['first_name' => 'Mia']],
        ]);
        $this->assertAddressInvalid('shipping', $id3,
            '4c: Invalid UK postcode format fails');

        $this->log("");
    }

    // ── Test Group 5: Extra data doesn't invalidate ──

    private function testGroup5_ExtraDataStillValid()
    {
        $this->log("--- Group 5: Extra filled fields don't invalidate ---");

        $this->applySettings([
            'shipping_address' => $this->allDisabled(),
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        // Address with ALL fields filled (more than required) → PASS
        $id1 = $this->createAddress([
            'type' => 'shipping',
            'name' => 'Full Name',
            'country' => $this->storeCountry ?: 'BD',
            'state' => 'BD-05',
            'city' => 'Dhaka',
            'postcode' => '1205',
            'address_1' => '123 Main St',
            'address_2' => 'Apt 4',
            'meta' => ['other_data' => ['first_name' => 'Noah', 'last_name' => 'Smith']],
        ]);
        $this->assertAddressValid('shipping', $id1,
            '5a: Address with all fields filled passes when only first_name required');

        $this->log("");
    }

    // ── Test Group 6: Country filter with store country ──

    private function testGroup6_CountryFilterWithStoreCountry()
    {
        if (!$this->storeCountry) {
            $this->log("--- Group 6: SKIPPED (no store country set) ---");
            return;
        }

        $this->log("--- Group 6: Country filter when country field disabled ---");

        $this->applySettings([
            'shipping_address' => $this->allDisabled(),
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        // Address with no country → PASS (country disabled, empty is OK)
        $id1 = $this->createAddress([
            'type' => 'shipping',
            'meta' => ['other_data' => ['first_name' => 'Oscar']],
        ]);
        $this->assertAddressValid('shipping', $id1,
            '6a: Address with no country passes when country field disabled');

        // Address matching store country → PASS
        $id2 = $this->createAddress([
            'type' => 'shipping',
            'country' => $this->storeCountry,
            'meta' => ['other_data' => ['first_name' => 'Pat']],
        ]);
        $this->assertAddressValid('shipping', $id2,
            '6b: Address matching store country passes');

        // Address with different country → FAIL
        $differentCountry = $this->storeCountry === 'US' ? 'GB' : 'US';
        $id3 = $this->createAddress([
            'type' => 'shipping',
            'country' => $differentCountry,
            'meta' => ['other_data' => ['first_name' => 'Quinn']],
        ]);
        $this->assertAddressInvalid('shipping', $id3,
            "6c: Address with different country ($differentCountry) fails when country disabled");

        $this->log("");
    }

    // ── Test Group 7: Billing type (name fields excluded from requirementsFields) ──

    private function testGroup7_BillingType()
    {
        $this->log("--- Group 7: Billing type address validation ---");

        $settings = $this->allDisabled();
        $settings['country'] = ['required' => 'yes', 'enabled' => 'yes'];

        $this->applySettings([
            'billing_address' => $settings,
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        // Billing address with valid country, no name fields → PASS
        // (billing type strips name requirements from requirementsFields)
        $id1 = $this->createAddress([
            'type' => 'billing',
            'country' => 'US',
        ]);
        $this->assertAddressValid('billing', $id1,
            '7a: Billing address passes without name fields (name excluded from billing validation)');

        // Billing address with no country when required → FAIL
        $id2 = $this->createAddress([
            'type' => 'billing',
        ]);
        $this->assertAddressInvalid('billing', $id2,
            '7b: Billing address without required country fails');

        // Billing address with valid country + name data → PASS
        $id3 = $this->createAddress([
            'type' => 'billing',
            'country' => 'GB',
            'name' => 'John Doe',
            'meta' => ['other_data' => ['first_name' => 'John', 'last_name' => 'Doe']],
        ]);
        $this->assertAddressValid('billing', $id3,
            '7c: Billing address with country and name passes');

        $this->log("");
    }

    // ── Test Group 8: Optional fields when provided should not block ──

    private function testGroup8_OptionalFieldsProvided()
    {
        $this->log("--- Group 8: Optional fields with values don't block ---");

        $settings = $this->allDisabled();
        $settings['country'] = ['required' => 'yes', 'enabled' => 'yes'];
        $settings['city'] = ['required' => 'no', 'enabled' => 'yes'];
        $settings['address_1'] = ['required' => 'no', 'enabled' => 'yes'];
        $settings['phone'] = ['required' => 'no', 'enabled' => 'yes'];

        $this->applySettings([
            'shipping_address' => $settings,
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        // Optional city/address filled → PASS
        $id1 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'city' => 'New York',
            'address_1' => '123 Broadway',
            'meta' => ['other_data' => ['first_name' => 'Opt1']],
        ]);
        $this->assertAddressValid('shipping', $id1,
            '8a: Address with optional fields filled passes');

        // Optional city/address empty → PASS
        $id2 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'meta' => ['other_data' => ['first_name' => 'Opt2']],
        ]);
        $this->assertAddressValid('shipping', $id2,
            '8b: Address with optional fields empty passes');

        $this->log("");
    }

    // ── Test Group 9: State optional but provided with invalid value ──

    private function testGroup9_StateOptionalButProvided()
    {
        $this->log("--- Group 9: State optional but provided ---");

        $settings = $this->allDisabled();
        $settings['country'] = ['required' => 'yes', 'enabled' => 'yes'];
        $settings['state'] = ['required' => 'no', 'enabled' => 'yes'];

        $this->applySettings([
            'shipping_address' => $settings,
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        // Optional state, empty → PASS
        $id1 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'meta' => ['other_data' => ['first_name' => 'St1']],
        ]);
        $this->assertAddressValid('shipping', $id1,
            '9a: Empty optional state passes');

        // Optional state, valid value → PASS
        $usStates = LocalizationManager::getInstance()->countryStates('US');
        $validUsState = !empty($usStates) ? array_key_first($usStates) : 'AL';
        $id2 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'state' => $validUsState,
            'meta' => ['other_data' => ['first_name' => 'St2']],
        ]);
        $this->assertAddressValid('shipping', $id2,
            "9b: Valid optional state ($validUsState) passes");

        // Optional state, INVALID value → FAIL (provided but doesn't match country)
        $id3 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'state' => 'FAKE_STATE',
            'meta' => ['other_data' => ['first_name' => 'St3']],
        ]);
        $this->assertAddressInvalid('shipping', $id3,
            '9c: Invalid optional state value still fails validation');

        $this->log("");
    }

    // ── Test Group 10: Postcode for country without postcode rules ──

    private function testGroup10_PostcodeCountryWithoutRules()
    {
        $this->log("--- Group 10: Postcode for countries without format rules ---");

        $settings = $this->allDisabled();
        $settings['country'] = ['required' => 'yes', 'enabled' => 'yes'];
        $settings['postcode'] = ['required' => 'yes', 'enabled' => 'yes'];

        $this->applySettings([
            'shipping_address' => $settings,
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        // Country without strict postcode rules (e.g., BD) + any postcode → PASS
        $id1 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'BD',
            'postcode' => '1205',
            'meta' => ['other_data' => ['first_name' => 'Pc1']],
        ]);
        $this->assertAddressValid('shipping', $id1,
            '10a: Postcode for country without strict format rules passes');

        // Valid US ZIP code → PASS
        $id2 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'postcode' => '90210',
            'meta' => ['other_data' => ['first_name' => 'Pc2']],
        ]);
        $this->assertAddressValid('shipping', $id2,
            '10b: Valid US ZIP code passes');

        // Invalid US ZIP code → FAIL
        $id3 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'postcode' => 'ABCDE',
            'meta' => ['other_data' => ['first_name' => 'Pc3']],
        ]);
        $this->assertAddressInvalid('shipping', $id3,
            '10c: Invalid US ZIP code fails');

        // Postcode with country disabled → no country to validate format against
        // (never assume store country for existing address validation)
        $settings2 = $this->allDisabled();
        $settings2['postcode'] = ['required' => 'yes', 'enabled' => 'yes'];

        $this->applySettings([
            'shipping_address' => $settings2,
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        // Postcode present, no country → PASS (presence check passes, no format validation possible)
        $id4 = $this->createAddress([
            'type' => 'shipping',
            'postcode' => '1205',
            'meta' => ['other_data' => ['first_name' => 'Pc4']],
        ]);
        $this->assertAddressValid('shipping', $id4,
            '10d: Postcode present but no country skips format validation (passes presence check)');

        // Postcode missing, no country → FAIL (required but empty)
        $id5 = $this->createAddress([
            'type' => 'shipping',
            'meta' => ['other_data' => ['first_name' => 'Pc5']],
        ]);
        $this->assertAddressInvalid('shipping', $id5,
            '10e: Postcode missing when required fails even without country');

        // Any string postcode with no country → PASS (no country = no format to check)
        $id6 = $this->createAddress([
            'type' => 'shipping',
            'postcode' => 'ANYTHING',
            'meta' => ['other_data' => ['first_name' => 'Pc6']],
        ]);
        $this->assertAddressValid('shipping', $id6,
            '10f: Any postcode string passes when no country for format validation');

        $this->log("");
    }

    // ── Test Group 11: Name field fallback logic ──

    private function testGroup11_NameFallbacks()
    {
        $this->log("--- Group 11: Name fallback logic ---");

        $this->applySettings([
            'shipping_address' => $this->allDisabled(),
            'basic_info' => [
                'full_name' => ['required' => 'yes', 'enabled' => 'yes'],
                'first_name' => ['required' => 'no', 'enabled' => 'no'],
                'last_name' => ['required' => 'no', 'enabled' => 'no'],
                'email' => ['required' => 'yes', 'enabled' => 'yes'],
                'company_name' => ['required' => 'no', 'enabled' => 'no'],
            ],
        ]);

        // full_name required, has name field → PASS
        $id1 = $this->createAddress([
            'type' => 'shipping',
            'name' => 'Alice Wonder',
        ]);
        $this->assertAddressValid('shipping', $id1,
            '11a: Address with name field passes full_name requirement');

        // full_name required, no name but has first+last in meta → PASS (fallback)
        $id2 = $this->createAddress([
            'type' => 'shipping',
            'meta' => ['other_data' => ['first_name' => 'Bob', 'last_name' => 'Builder']],
        ]);
        $this->assertAddressValid('shipping', $id2,
            '11b: full_name falls back to meta first_name + last_name');

        // full_name required, no name data at all → FAIL
        $id3 = $this->createAddress([
            'type' => 'shipping',
        ]);
        $this->assertAddressInvalid('shipping', $id3,
            '11c: Address with no name data fails full_name requirement');

        // first_name required, name field present (should split) → PASS
        $this->applySettings([
            'shipping_address' => $this->allDisabled(),
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        $id4 = $this->createAddress([
            'type' => 'shipping',
            'name' => 'Charlie Brown',
        ]);
        $this->assertAddressValid('shipping', $id4,
            '11d: first_name falls back to splitting name field');

        $this->log("");
    }

    // ── Test Group 12: Multiple required fields combined ──

    private function testGroup12_MultipleFieldCombinations()
    {
        $this->log("--- Group 12: Multiple required fields combined ---");

        $settings = $this->allDisabled();
        $settings['country'] = ['required' => 'yes', 'enabled' => 'yes'];
        $settings['state'] = ['required' => 'yes', 'enabled' => 'yes'];
        $settings['city'] = ['required' => 'yes', 'enabled' => 'yes'];
        $settings['postcode'] = ['required' => 'yes', 'enabled' => 'yes'];
        $settings['address_1'] = ['required' => 'yes', 'enabled' => 'yes'];

        $this->applySettings([
            'shipping_address' => $settings,
            'basic_info' => $this->basicInfoFirstNameRequired(),
        ]);

        $localization = LocalizationManager::getInstance();
        $usStates = $localization->countryStates('US');
        $validUsState = !empty($usStates) ? array_key_first($usStates) : 'AL';

        // All required fields present and valid → PASS
        $id1 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'state' => $validUsState,
            'city' => 'New York',
            'postcode' => '10001',
            'address_1' => '350 Fifth Avenue',
            'meta' => ['other_data' => ['first_name' => 'Combo1']],
        ]);
        $this->assertAddressValid('shipping', $id1,
            '12a: All required fields valid passes');

        // Missing city → FAIL
        $id2 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'state' => $validUsState,
            'postcode' => '10001',
            'address_1' => '350 Fifth Avenue',
            'meta' => ['other_data' => ['first_name' => 'Combo2']],
        ]);
        $this->assertAddressInvalid('shipping', $id2,
            '12b: Missing required city fails');

        // Missing address_1 → FAIL
        $id3 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'state' => $validUsState,
            'city' => 'New York',
            'postcode' => '10001',
            'meta' => ['other_data' => ['first_name' => 'Combo3']],
        ]);
        $this->assertAddressInvalid('shipping', $id3,
            '12c: Missing required address_1 fails');

        // Valid country but invalid state → FAIL
        $id4 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'US',
            'state' => 'BD-05',
            'city' => 'New York',
            'postcode' => '10001',
            'address_1' => '350 Fifth Avenue',
            'meta' => ['other_data' => ['first_name' => 'Combo4']],
        ]);
        $this->assertAddressInvalid('shipping', $id4,
            '12d: Valid country with mismatched state fails');

        // Valid everything except postcode format → FAIL
        $id5 = $this->createAddress([
            'type' => 'shipping',
            'country' => 'GB',
            'state' => '',
            'city' => 'London',
            'postcode' => '999',
            'address_1' => '10 Downing Street',
            'meta' => ['other_data' => ['first_name' => 'Combo5']],
        ]);
        $this->assertAddressInvalid('shipping', $id5,
            '12e: Valid fields except invalid postcode format fails');

        // Country with no states → state required but empty is OK
        // (e.g., some countries have no state list)
        $countriesWithoutStates = $this->findCountryWithoutStates($localization);
        if ($countriesWithoutStates) {
            $id6 = $this->createAddress([
                'type' => 'shipping',
                'country' => $countriesWithoutStates,
                'city' => 'SomeCity',
                'postcode' => '12345',
                'address_1' => '1 Main St',
                'meta' => ['other_data' => ['first_name' => 'Combo6']],
            ]);
            $this->assertAddressValid('shipping', $id6,
                "12f: Country ($countriesWithoutStates) without states list allows empty state even when required");
        } else {
            $this->log("  SKIP: 12f - Could not find a country without states");
        }

        $this->log("");
    }

    private function findCountryWithoutStates($localization): string
    {
        // Try a few countries known to often not have states
        $candidates = ['SG', 'HK', 'BN', 'MC', 'VA', 'LU'];
        foreach ($candidates as $code) {
            $states = $localization->countryStates($code);
            if (empty($states)) {
                return $code;
            }
        }
        return '';
    }

    // ── Helpers ──

    private function getOrCreateTestCustomer()
    {
        $customer = Customer::first();
        return $customer;
    }

    private function createAddress(array $data): int
    {
        $data = array_merge([
            'customer_id' => $this->customer->id,
            'is_primary' => '0',
            'status' => 'active',
            'type' => 'shipping',
        ], $data);

        $address = CustomerAddresses::create($data);
        $this->createdAddressIds[] = $address->id;
        return $address->id;
    }

    private function getValidatedIds(string $type): array
    {
        $config = [
            'type' => $type,
            'product_type' => 'physical',
            'with_shipping' => false,
        ];
        $validated = AddressHelper::getCustomerValidatedAddresses($config, $this->customer);
        return array_keys($validated);
    }

    private function assertAddressValid(string $type, int $addressId, string $testName)
    {
        $validIds = $this->getValidatedIds($type);
        $pass = in_array($addressId, $validIds);
        $this->results[] = [
            'name' => $testName,
            'pass' => $pass,
            'reason' => $pass ? '' : "Address ID $addressId not in validated list: [" . implode(',', $validIds) . "]",
        ];
        $this->log(($pass ? '  PASS' : '  FAIL') . ": $testName");
    }

    private function assertAddressInvalid(string $type, int $addressId, string $testName)
    {
        $validIds = $this->getValidatedIds($type);
        $pass = !in_array($addressId, $validIds);
        $this->results[] = [
            'name' => $testName,
            'pass' => $pass,
            'reason' => $pass ? '' : "Address ID $addressId should NOT be in validated list but was",
        ];
        $this->log(($pass ? '  PASS' : '  FAIL') . ": $testName");
    }

    private function applySettings(array $overrides)
    {
        $settings = $this->originalSettings;
        foreach ($overrides as $key => $value) {
            $settings[$key] = $value;
        }
        fluent_cart_update_option('_fc_checkout_fields', $settings);
    }

    private function restoreSettings()
    {
        fluent_cart_update_option('_fc_checkout_fields', $this->originalSettings);
        $this->log("Settings restored to original.");
    }

    private function cleanup()
    {
        if (!empty($this->createdAddressIds)) {
            CustomerAddresses::whereIn('id', $this->createdAddressIds)->delete();
            $this->log("Cleaned up " . count($this->createdAddressIds) . " test addresses.");
        }
    }

    private function allDisabled(): array
    {
        return [
            'full_name' => ['required' => 'yes', 'enabled' => 'yes'],
            'country' => ['required' => 'yes', 'enabled' => 'no'],
            'state' => ['required' => 'yes', 'enabled' => 'no'],
            'address_1' => ['required' => 'yes', 'enabled' => 'no'],
            'address_2' => ['required' => 'no', 'enabled' => 'no'],
            'city' => ['required' => 'yes', 'enabled' => 'no'],
            'postcode' => ['required' => 'yes', 'enabled' => 'no'],
            'phone' => ['required' => 'no', 'enabled' => 'no'],
            'company_name' => ['required' => 'no', 'enabled' => 'no'],
        ];
    }

    private function basicInfoFirstNameRequired(): array
    {
        return [
            'full_name' => ['required' => 'no', 'enabled' => 'no'],
            'first_name' => ['required' => 'yes', 'enabled' => 'yes'],
            'last_name' => ['required' => 'no', 'enabled' => 'yes'],
            'email' => ['required' => 'yes', 'enabled' => 'yes'],
            'company_name' => ['required' => 'no', 'enabled' => 'no'],
        ];
    }

    private function log(string $msg)
    {
        if ($this->isHtml) {
            echo htmlspecialchars($msg) . "<br>\n";
        } else {
            echo $msg . "\n";
        }
    }
}

// ── Entry Points ──

// Browser: ?fct_test_address=1 (admin only)
if (!defined('WP_CLI')) {
    add_action('init', function () {
        if (empty($_GET['fct_test_address']) || !current_user_can('manage_options')) {
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<pre style="font-family:monospace;font-size:14px;padding:20px;background:#1a1a2e;color:#e0e0e0;">';
        $tester = new AddressValidationTester(true);
        $results = $tester->run();
        echo '</pre>';

        $passed = count(array_filter($results, function ($r) { return $r['pass']; }));
        $failed = count($results) - $passed;

        echo '<script>document.title = "Address Tests: ' . $passed . ' passed, ' . $failed . ' failed";</script>';
        echo '<script>window.__fct_test_results = ' . json_encode($results) . ';</script>';
        exit;
    });
} else {
    // WP CLI: wp eval-file
    $tester = new AddressValidationTester(false);
    $tester->run();
}
