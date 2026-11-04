<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Updating a shipping class" section, items 52-55.
 */
class UpdateShippingClassCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [52]
     *   "Admin can rename an existing shipping class."
     */
    public function adminCanRenameExistingShippingClass(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class = (new ShippingClassFactory())->create(['name' => 'OldName52', 'cost' => 5, 'type' => 'fixed']);

        [, $body] = $I->put('/fluent-cart/v2/shipping/classes/' . (int) $class->id, [
            'name' => 'RenamedClass52',
            'cost' => 5,
            'type' => 'fixed',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $name = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $class->id
        ));
        Assert::assertSame('RenamedClass52', $name, 'rename must persist the new name');
    }

    /**
     * Spec: Cases/Functional/shipping.md [53]
     *   "Admin can change a shipping class type from fixed to percentage and back again."
     */
    public function adminCanChangeShippingClassTypeFromFixedToPercentageAndBack(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class = (new ShippingClassFactory())->create(['name' => 'TypeFlip53', 'cost' => 5, 'type' => 'fixed']);

        [, $first] = $I->put('/fluent-cart/v2/shipping/classes/' . (int) $class->id, [
            'name' => 'TypeFlip53',
            'cost' => 5,
            'type' => 'percentage',
        ]);
        $I->seeResponseCodeIs(200);

        global $wpdb;
        $afterFirst = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT type FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $class->id
        ));
        Assert::assertSame('percentage', $afterFirst, 'type must persist as percentage after first update');

        [, $second] = $I->put('/fluent-cart/v2/shipping/classes/' . (int) $class->id, [
            'name' => 'TypeFlip53',
            'cost' => 5,
            'type' => 'fixed',
        ]);
        $I->seeResponseCodeIs(200);

        $afterSecond = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT type FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $class->id
        ));
        Assert::assertSame('fixed', $afterSecond, 'type must persist as fixed after second update');
    }

    /**
     * Spec: Cases/Functional/shipping.md [54]
     *   "Admin can toggle the per-item flag on an existing shipping class and the new value persists."
     */
    public function adminCanTogglePerItemFlagOnShippingClass(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class = (new ShippingClassFactory())->create(['name' => 'PerItemToggle54', 'cost' => 5, 'type' => 'fixed', 'per_item' => 0]);

        [, $body] = $I->put('/fluent-cart/v2/shipping/classes/' . (int) $class->id, [
            'name'     => 'PerItemToggle54',
            'cost'     => 5,
            'type'     => 'fixed',
            'per_item' => 1,
        ]);
        $I->seeResponseCodeIs(200);

        global $wpdb;
        $perItem = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT per_item FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $class->id
        ));
        Assert::assertSame(1, $perItem, 'per_item must persist as 1 after toggle');
    }

    /**
     * Spec: Cases/Functional/shipping.md [135]
     *   "Updating a shipping class without sending the per-item flag keeps the stored per-item value."
     */
    public function updatingShippingClassWithoutPerItemKeepsStoredValue(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class = (new ShippingClassFactory())->create(['name' => 'PerItemKeep135', 'cost' => 5, 'type' => 'fixed', 'per_item' => 1]);

        $I->put('/fluent-cart/v2/shipping/classes/' . (int) $class->id, [
            'name' => 'PerItemKeep135-renamed',
            'cost' => 5,
            'type' => 'fixed',
        ]);
        $I->seeResponseCodeIs(200);

        global $wpdb;
        $perItem = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT per_item FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $class->id
        ));
        Assert::assertSame(1, $perItem, 'an update that omits per_item must keep the stored per_item');
    }

    /**
     * Spec: Cases/Functional/shipping.md [55]
     *   "Updating a shipping class that does not exist returns a clear \"not found\" error."
     */
    public function updatingNonExistentShippingClassReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->put('/fluent-cart/v2/shipping/classes/999999', [
            'name' => 'Whatever',
            'cost' => 5,
            'type' => 'fixed',
        ]);

        Assert::assertGreaterThanOrEqual(400, $status, 'updating a missing class must be a non-2xx status. Body: ' . wp_json_encode($body));
        Assert::assertLessThan(500, $status, 'updating a missing class must NOT 500. Status: ' . $status);
        Assert::assertEmpty($body['shipping_class'] ?? null, 'response must NOT echo a shipping_class payload for missing id');
    }
}
