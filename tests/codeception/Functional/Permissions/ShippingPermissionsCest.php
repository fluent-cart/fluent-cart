<?php

namespace Tests\Functional\Permissions;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Permissions" + "Authentication and CSRF" sections, items 102-126.
 *
 * All admin shipping routes (under /shipping, StoreSensitivePolicy) check the
 * 'store/sensitive' capability. Per PermissionManager::getAllRoles only super_admin
 * carries that capability; manager/worker/accountant do not. The public storefront
 * lookup (/checkout/get-*-shipping-*) uses PublicPolicy and is unauthenticated.
 *
 * Helpers in this Cest dispatch one representative endpoint per capability category
 * (zones, classes, methods, packages, lookup helpers).
 */
class ShippingPermissionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    // ----------------------------------------------------------------
    // Representative dispatch helpers per capability category.
    // Each returns [readStatus, writeStatus, readBody, writeBody].
    // ----------------------------------------------------------------

    private function dispatchZoneRouteForCurrentUser(FunctionalTester $I): array
    {
        [$readStatus, $readBody]   = $I->get('/fluent-cart/v2/shipping/zones');
        [$writeStatus, $writeBody] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'   => 'Perm-Probe-Zone-' . uniqid('', true),
            'region' => 'US',
        ]);
        return [$readStatus, $writeStatus, $readBody, $writeBody];
    }

    private function dispatchClassRouteForCurrentUser(FunctionalTester $I): array
    {
        [$readStatus, $readBody]   = $I->get('/fluent-cart/v2/shipping/classes');
        [$writeStatus, $writeBody] = $I->post('/fluent-cart/v2/shipping/classes', [
            'name' => 'Perm-Probe-Class-' . uniqid('', true),
            'cost' => 1,
            'type' => 'fixed',
        ]);
        return [$readStatus, $writeStatus, $readBody, $writeBody];
    }

    private function dispatchMethodRouteForCurrentUser(FunctionalTester $I, int $zoneId): array
    {
        // No GET /methods endpoint exists; probe write + delete instead.
        [$writeStatus, $writeBody] = $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id' => $zoneId,
            'title'   => 'Perm-Probe-Method-' . uniqid('', true),
            'type'    => 'flat_rate',
            'states'  => [],
        ]);
        [$deleteStatus, $deleteBody] = $I->delete('/fluent-cart/v2/shipping/methods/999999');
        return [$writeStatus, $deleteStatus, $writeBody, $deleteBody];
    }

    private function dispatchPackageRouteForCurrentUser(FunctionalTester $I): array
    {
        [$readStatus, $readBody]   = $I->get('/fluent-cart/v2/shipping/packages');
        [$writeStatus, $writeBody] = $I->post('/fluent-cart/v2/shipping/packages', ['packages' => []]);
        return [$readStatus, $writeStatus, $readBody, $writeBody];
    }

    private function dispatchHelperRouteForCurrentUser(FunctionalTester $I): array
    {
        [$statesStatus, $statesBody]       = $I->get('/fluent-cart/v2/shipping/zone/states', ['country_code' => 'US']);
        [$countriesStatus, $countriesBody] = $I->get('/fluent-cart/v2/shipping/zone/countries');
        return [$statesStatus, $countriesStatus, $statesBody, $countriesBody];
    }

    private function assertForbiddenPair(int $a, int $b, array $bodyA, array $bodyB, string $label): void
    {
        Assert::assertSame(403, $a, "{$label}: first endpoint must return 403. Body: " . wp_json_encode($bodyA));
        Assert::assertSame(403, $b, "{$label}: second endpoint must return 403. Body: " . wp_json_encode($bodyB));
        Assert::assertSame('rest_forbidden', $bodyA['code'] ?? '', "{$label}: first endpoint must surface rest_forbidden code");
        Assert::assertSame('rest_forbidden', $bodyB['code'] ?? '', "{$label}: second endpoint must surface rest_forbidden code");
    }

    private function assertGuestUnauthorizedPair(int $a, int $b, array $bodyA, array $bodyB, string $label): void
    {
        Assert::assertSame(401, $a, "{$label}: first endpoint must return 401 for guest. Body: " . wp_json_encode($bodyA));
        Assert::assertSame(401, $b, "{$label}: second endpoint must return 401 for guest. Body: " . wp_json_encode($bodyB));
        Assert::assertSame('rest_forbidden', $bodyA['code'] ?? '', "{$label}: first endpoint must surface rest_forbidden code for guest");
        Assert::assertSame('rest_forbidden', $bodyB['code'] ?? '', "{$label}: second endpoint must surface rest_forbidden code for guest");
    }

    // ----------------------------------------------------------------
    // Items 102-106: Admin (super_admin) CAN manage every category.
    // ----------------------------------------------------------------

    /**
     * Spec: Cases/Functional/shipping.md [102]
     *   "Admin can manage shipping zones."
     */
    public function adminCanManageShippingZones(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$r, $w] = $this->dispatchZoneRouteForCurrentUser($I);
        Assert::assertSame(200, $r, 'admin must succeed reading shipping zones');
        Assert::assertSame(200, $w, 'admin must succeed creating a shipping zone');
    }

    /**
     * Spec: Cases/Functional/shipping.md [103]
     *   "Admin can manage shipping classes."
     */
    public function adminCanManageShippingClasses(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$r, $w] = $this->dispatchClassRouteForCurrentUser($I);
        Assert::assertSame(200, $r, 'admin must succeed reading shipping classes');
        Assert::assertSame(200, $w, 'admin must succeed creating a shipping class');
    }

    /**
     * Spec: Cases/Functional/shipping.md [104]
     *   "Admin can manage shipping methods."
     */
    public function adminCanManageShippingMethods(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = (new ShippingZoneFactory())->create(['name' => 'AdminPermMethodZone', 'region' => 'US']);
        [$write, $delete] = $this->dispatchMethodRouteForCurrentUser($I, (int) $zone->id);
        Assert::assertSame(200, $write, 'admin must succeed creating a shipping method');
        Assert::assertLessThan(500, $delete, 'admin must reach a clean 4xx (not 403/500) on delete of a missing method id');
        Assert::assertNotSame(403, $delete, 'admin must NOT be forbidden from deleting (404 expected for missing id)');
    }

    /**
     * Spec: Cases/Functional/shipping.md [105]
     *   "Admin can manage package presets."
     */
    public function adminCanManagePackagePresets(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$r, $w] = $this->dispatchPackageRouteForCurrentUser($I);
        Assert::assertSame(200, $r, 'admin must succeed reading package presets');
        Assert::assertSame(200, $w, 'admin must succeed saving package presets');
    }

    /**
     * Spec: Cases/Functional/shipping.md [106]
     *   "Admin can use the admin country and state lookup helpers."
     */
    public function adminCanUseAdminCountryAndStateLookupHelpers(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$states, $countries] = $this->dispatchHelperRouteForCurrentUser($I);
        Assert::assertSame(200, $states, 'admin must succeed reading the states lookup');
        Assert::assertSame(200, $countries, 'admin must succeed reading the countries lookup');
    }

    // ----------------------------------------------------------------
    // Items 107-111: Store manager CANNOT manage any category.
    // ----------------------------------------------------------------

    /**
     * Spec: Cases/Functional/shipping.md [107]
     *   "Store manager cannot manage shipping zones."
     */
    public function storeManagerCannotManageShippingZones(FunctionalTester $I): void
    {
        $I->loginAsManager();
        [$r, $w, $rb, $wb] = $this->dispatchZoneRouteForCurrentUser($I);
        $this->assertForbiddenPair($r, $w, $rb, $wb, 'store manager / zones');
    }

    /**
     * Spec: Cases/Functional/shipping.md [108]
     *   "Store manager cannot manage shipping classes."
     */
    public function storeManagerCannotManageShippingClasses(FunctionalTester $I): void
    {
        $I->loginAsManager();
        [$r, $w, $rb, $wb] = $this->dispatchClassRouteForCurrentUser($I);
        $this->assertForbiddenPair($r, $w, $rb, $wb, 'store manager / classes');
    }

    /**
     * Spec: Cases/Functional/shipping.md [109]
     *   "Store manager cannot manage shipping methods."
     */
    public function storeManagerCannotManageShippingMethods(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = (new ShippingZoneFactory())->create(['name' => 'SmMethodPerm', 'region' => 'US']);
        $existing = (new ShippingMethodFactory())->create(['zone_id' => (int) $zone->id, 'title' => 'SmMethodExisting']);
        $I->loginAsManager();
        [$wStatus, $dStatus, $wb, $db] = $this->dispatchMethodRouteForCurrentUser($I, (int) $zone->id);
        $this->assertForbiddenPair($wStatus, $dStatus, $wb, $db, 'store manager / methods');
    }

    /**
     * Spec: Cases/Functional/shipping.md [110]
     *   "Store manager cannot manage package presets."
     */
    public function storeManagerCannotManagePackagePresets(FunctionalTester $I): void
    {
        $I->loginAsManager();
        [$r, $w, $rb, $wb] = $this->dispatchPackageRouteForCurrentUser($I);
        $this->assertForbiddenPair($r, $w, $rb, $wb, 'store manager / packages');
    }

    /**
     * Spec: Cases/Functional/shipping.md [111]
     *   "Store manager cannot use the admin country and state lookup helpers."
     */
    public function storeManagerCannotUseAdminCountryAndStateLookupHelpers(FunctionalTester $I): void
    {
        $I->loginAsManager();
        [$s, $c, $sb, $cb] = $this->dispatchHelperRouteForCurrentUser($I);
        $this->assertForbiddenPair($s, $c, $sb, $cb, 'store manager / helpers');
    }

    // ----------------------------------------------------------------
    // Items 112-116: Worker CANNOT manage any category.
    // ----------------------------------------------------------------

    /**
     * Spec: Cases/Functional/shipping.md [112]
     *   "Worker cannot manage shipping zones."
     */
    public function workerCannotManageShippingZones(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        [$r, $w, $rb, $wb] = $this->dispatchZoneRouteForCurrentUser($I);
        $this->assertForbiddenPair($r, $w, $rb, $wb, 'worker / zones');
    }

    /**
     * Spec: Cases/Functional/shipping.md [113]
     *   "Worker cannot manage shipping classes."
     */
    public function workerCannotManageShippingClasses(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        [$r, $w, $rb, $wb] = $this->dispatchClassRouteForCurrentUser($I);
        $this->assertForbiddenPair($r, $w, $rb, $wb, 'worker / classes');
    }

    /**
     * Spec: Cases/Functional/shipping.md [114]
     *   "Worker cannot manage shipping methods."
     */
    public function workerCannotManageShippingMethods(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = (new ShippingZoneFactory())->create(['name' => 'WorkerMethodPerm', 'region' => 'US']);
        $I->loginAsWorker();
        [$wStatus, $dStatus, $wb, $db] = $this->dispatchMethodRouteForCurrentUser($I, (int) $zone->id);
        $this->assertForbiddenPair($wStatus, $dStatus, $wb, $db, 'worker / methods');
    }

    /**
     * Spec: Cases/Functional/shipping.md [115]
     *   "Worker cannot manage package presets."
     */
    public function workerCannotManagePackagePresets(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        [$r, $w, $rb, $wb] = $this->dispatchPackageRouteForCurrentUser($I);
        $this->assertForbiddenPair($r, $w, $rb, $wb, 'worker / packages');
    }

    /**
     * Spec: Cases/Functional/shipping.md [116]
     *   "Worker cannot use the admin country and state lookup helpers."
     */
    public function workerCannotUseAdminCountryAndStateLookupHelpers(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        [$s, $c, $sb, $cb] = $this->dispatchHelperRouteForCurrentUser($I);
        $this->assertForbiddenPair($s, $c, $sb, $cb, 'worker / helpers');
    }

    // ----------------------------------------------------------------
    // Items 117-121: Accountant CANNOT manage any category.
    // ----------------------------------------------------------------

    /**
     * Spec: Cases/Functional/shipping.md [117]
     *   "Accountant cannot manage shipping zones."
     */
    public function accountantCannotManageShippingZones(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [$r, $w, $rb, $wb] = $this->dispatchZoneRouteForCurrentUser($I);
        $this->assertForbiddenPair($r, $w, $rb, $wb, 'accountant / zones');
    }

    /**
     * Spec: Cases/Functional/shipping.md [118]
     *   "Accountant cannot manage shipping classes."
     */
    public function accountantCannotManageShippingClasses(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [$r, $w, $rb, $wb] = $this->dispatchClassRouteForCurrentUser($I);
        $this->assertForbiddenPair($r, $w, $rb, $wb, 'accountant / classes');
    }

    /**
     * Spec: Cases/Functional/shipping.md [119]
     *   "Accountant cannot manage shipping methods."
     */
    public function accountantCannotManageShippingMethods(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = (new ShippingZoneFactory())->create(['name' => 'AccMethodPerm', 'region' => 'US']);
        $I->loginAsAccountant();
        [$wStatus, $dStatus, $wb, $db] = $this->dispatchMethodRouteForCurrentUser($I, (int) $zone->id);
        $this->assertForbiddenPair($wStatus, $dStatus, $wb, $db, 'accountant / methods');
    }

    /**
     * Spec: Cases/Functional/shipping.md [120]
     *   "Accountant cannot manage package presets."
     */
    public function accountantCannotManagePackagePresets(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [$r, $w, $rb, $wb] = $this->dispatchPackageRouteForCurrentUser($I);
        $this->assertForbiddenPair($r, $w, $rb, $wb, 'accountant / packages');
    }

    /**
     * Spec: Cases/Functional/shipping.md [121]
     *   "Accountant cannot use the admin country and state lookup helpers."
     */
    public function accountantCannotUseAdminCountryAndStateLookupHelpers(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [$s, $c, $sb, $cb] = $this->dispatchHelperRouteForCurrentUser($I);
        $this->assertForbiddenPair($s, $c, $sb, $cb, 'accountant / helpers');
    }

    // ----------------------------------------------------------------
    // Items 122-123: Logged-out visitor + customer-side guest.
    // ----------------------------------------------------------------

    /**
     * Spec: Cases/Functional/shipping.md [122]
     *   "A logged-out visitor cannot access any admin shipping endpoint."
     */
    public function loggedOutVisitorCannotAccessAdminShippingEndpoints(FunctionalTester $I): void
    {
        $I->logout();

        [$z1, $b1] = $I->get('/fluent-cart/v2/shipping/zones');
        $this->assertGuestUnauthorizedPair($z1, $z1, $b1, $b1, 'guest / zones list (probe 1)');

        [$z2, $b2] = $I->post('/fluent-cart/v2/shipping/zones', ['name' => 'Guest-Probe', 'region' => 'US']);
        Assert::assertSame(401, $z2, 'guest must be 401 unauthorized on POST zones. Body: ' . wp_json_encode($b2));
        Assert::assertSame('rest_forbidden', $b2['code'] ?? '', 'guest POST zones must surface rest_forbidden code');

        [$c1, $b3] = $I->get('/fluent-cart/v2/shipping/classes');
        Assert::assertSame(401, $c1, 'guest must be 401 unauthorized on GET classes. Body: ' . wp_json_encode($b3));

        [$p1, $b4] = $I->get('/fluent-cart/v2/shipping/packages');
        Assert::assertSame(401, $p1, 'guest must be 401 unauthorized on GET packages. Body: ' . wp_json_encode($b4));

        [$h1, $b5] = $I->get('/fluent-cart/v2/shipping/zone/states', ['country_code' => 'US']);
        Assert::assertSame(401, $h1, 'guest must be 401 unauthorized on GET zone/states. Body: ' . wp_json_encode($b5));
    }

    /**
     * Spec: Cases/Functional/shipping.md [123]
     *   "A logged-out shopper can use the public shipping lookup without authentication."
     */
    public function loggedOutShopperCanUsePublicShippingLookupWithoutAuth(FunctionalTester $I): void
    {
        $I->logout();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'PublicAuthZone123', 'region' => 'US', 'shipping_class_id' => null]);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'PublicMethod123',
            'is_enabled' => 1,
            'states'     => [],
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'US']);
        Assert::assertSame(200, $status, 'public lookup must succeed unauthenticated. Body: ' . wp_json_encode($body));
        $ids = array_map('intval', array_column((array) ($body['available_shipping_methods'] ?? []), 'id'));
        Assert::assertContains((int) $method->id, $ids, 'public lookup must return the seeded method to a logged-out shopper');

        [$ciStatus, $ciBody] = $I->get('/fluent-cart/v2/checkout/get-country-info', ['country_code' => 'US']);
        Assert::assertSame(200, $ciStatus, 'public country-info must succeed unauthenticated. Body: ' . wp_json_encode($ciBody));
    }

    /**
     * Spec: Cases/Functional/shipping.md [126]
     *   "The public shipping lookup does not require an admin security token."
     */
    public function publicShippingLookupDoesNotRequireAdminSecurityToken(FunctionalTester $I): void
    {
        $I->logout();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'NoTokenZone126', 'region' => 'US', 'shipping_class_id' => null]);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'NoTokenMethod126',
            'is_enabled' => 1,
            'states'     => [],
        ]);

        // Helper's GET dispatch attaches no X-WP-Nonce / _wpnonce / auth cookie, which is
        // the exact condition the spec asserts: a public storefront caller carries no
        // admin security token. We then explicitly clear any leftover REQUEST/SERVER
        // nonce keys from prior test methods so the route sees a truly token-less GET.
        unset($_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE']);
        [$status, $body] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'US']);

        Assert::assertSame(200, $status, 'public lookup must succeed without any nonce. Body: ' . wp_json_encode($body));
        $ids = array_map('intval', array_column((array) ($body['available_shipping_methods'] ?? []), 'id'));
        Assert::assertContains((int) $method->id, $ids, 'public lookup must still return the seeded method when called with no token');
    }
}
