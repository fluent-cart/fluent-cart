<?php

namespace Tests\Functional\Rest\Shipping;

use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Deleting a shipping class" section, items 56-58.
 */
class DeleteShippingClassCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [56]
     *   "Admin can delete a shipping class; every zone scoped to that class and every shipping method belonging to those zones is removed at the same time."
     */
    public function adminCanDeleteShippingClassCascadingZonesAndMethods(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class       = (new ShippingClassFactory())->create(['name' => 'CascadeClass56']);
        $zoneFactory = new ShippingZoneFactory();
        $scoped1     = $zoneFactory->create(['name' => 'CascadeScopedA56', 'shipping_class_id' => (int) $class->id, 'region' => 'US']);
        $scoped2     = $zoneFactory->create(['name' => 'CascadeScopedB56', 'shipping_class_id' => (int) $class->id, 'region' => 'GB']);
        $general     = $zoneFactory->create(['name' => 'CascadeGeneral56', 'shipping_class_id' => null,             'region' => 'US']);

        $methodFactory = new ShippingMethodFactory();
        $methodA = $methodFactory->create(['zone_id' => (int) $scoped1->id, 'title' => 'MA56']);
        $methodB = $methodFactory->create(['zone_id' => (int) $scoped2->id, 'title' => 'MB56']);
        $methodG = $methodFactory->create(['zone_id' => (int) $general->id, 'title' => 'MG56']);

        [, $body] = $I->delete('/fluent-cart/v2/shipping/classes/' . (int) $class->id);
        $I->seeResponseCodeIs(200);

        global $wpdb;
        $classRow = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $class->id
        ));
        Assert::assertSame(0, $classRow, 'deleted class row must be removed');

        $scopedZones = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_zones WHERE id IN (%d, %d)",
            $scoped1->id,
            $scoped2->id
        ));
        Assert::assertSame(0, $scopedZones, 'class-scoped zones must be cascade-removed');

        $generalZone = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d",
            $general->id
        ));
        Assert::assertSame(1, $generalZone, 'general (unscoped) zone must survive class deletion');

        $methodsInScoped = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_methods WHERE id IN (%d, %d)",
            $methodA->id,
            $methodB->id
        ));
        Assert::assertSame(0, $methodsInScoped, 'methods inside cascade-deleted zones must be removed');

        $methodInGeneral = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_methods WHERE id = %d",
            $methodG->id
        ));
        Assert::assertSame(1, $methodInGeneral, 'method inside unrelated general zone must survive');
    }

    /**
     * Spec: Cases/Functional/shipping.md [57]
     *   "Deleting a shipping class that is used by no zones removes only the class itself and leaves general zones untouched."
     */
    public function deletingUnusedShippingClassLeavesGeneralZonesUntouched(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class    = (new ShippingClassFactory())->create(['name' => 'UnusedClass57']);
        $general1 = (new ShippingZoneFactory())->create(['name' => 'General1-57', 'shipping_class_id' => null, 'region' => 'US']);
        $general2 = (new ShippingZoneFactory())->create(['name' => 'General2-57', 'shipping_class_id' => null, 'region' => 'GB']);

        [, $body] = $I->delete('/fluent-cart/v2/shipping/classes/' . (int) $class->id);
        $I->seeResponseCodeIs(200);

        global $wpdb;
        $classRow = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $class->id
        ));
        Assert::assertSame(0, $classRow, 'deleted unused class row must be removed');

        $generals = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_zones WHERE id IN (%d, %d)",
            $general1->id,
            $general2->id
        ));
        Assert::assertSame(2, $generals, 'general zones not scoped to the deleted class must remain untouched');
    }

    /**
     * Spec: Cases/Functional/shipping.md [136]
     *   "Deleting a shipping class removes it from every product and variation that used it, and leaves other classes' products untouched."
     */
    public function deletingShippingClassClearsProductAssignments(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $deleted = (new ShippingClassFactory())->create(['name' => 'Deleted136']);
        $kept    = (new ShippingClassFactory())->create(['name' => 'Kept136']);

        $usesDeleted = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['shipping_class' => (int) $deleted->id, 'tax_class' => 7]])
            ->withVariantAttributes(['shipping_class' => (int) $deleted->id])
            ->create();
        // Stored as a string by older product saves.
        $usesDeletedAsString = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['shipping_class' => (string) $deleted->id]])
            ->create();
        $usesKept = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['shipping_class' => (int) $kept->id]])
            ->withVariantAttributes(['shipping_class' => (int) $kept->id])
            ->create();

        $I->delete('/fluent-cart/v2/shipping/classes/' . (int) $deleted->id);
        $I->seeResponseCodeIs(200);

        $detail = ProductDetail::query()->where('post_id', $usesDeleted->ID)->first();
        Assert::assertEmpty($detail->other_info['shipping_class'] ?? null, 'product must no longer point at the deleted class');
        Assert::assertSame(7, $detail->other_info['tax_class'] ?? null, 'other product info must survive the cleanup');

        $stringDetail = ProductDetail::query()->where('post_id', $usesDeletedAsString->ID)->first();
        Assert::assertEmpty($stringDetail->other_info['shipping_class'] ?? null, 'a string class id must be cleared too');

        $variant = ProductVariation::query()->where('post_id', $usesDeleted->ID)->first();
        Assert::assertEmpty($variant->shipping_class, 'variation must no longer point at the deleted class');

        $keptDetail = ProductDetail::query()->where('post_id', $usesKept->ID)->first();
        Assert::assertSame((int) $kept->id, (int) ($keptDetail->other_info['shipping_class'] ?? 0), 'another class assignment must survive');
        $keptVariant = ProductVariation::query()->where('post_id', $usesKept->ID)->first();
        Assert::assertSame((int) $kept->id, (int) $keptVariant->shipping_class, 'another class variation assignment must survive');
    }

    /**
     * Spec: Cases/Functional/shipping.md [58]
     *   "Deleting a shipping class that does not exist returns a clear \"not found\" error."
     */
    public function deletingNonExistentShippingClassReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->delete('/fluent-cart/v2/shipping/classes/999999');

        Assert::assertGreaterThanOrEqual(400, $status, 'deleting a missing class must be a non-2xx status. Body: ' . wp_json_encode($body));
        Assert::assertLessThan(500, $status, 'deleting a missing class must NOT 500. Status: ' . $status);
    }
}
