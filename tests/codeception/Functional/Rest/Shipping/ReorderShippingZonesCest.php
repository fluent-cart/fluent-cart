<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Reordering shipping zones" section, items 27-29.
 */
class ReorderShippingZonesCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [27]
     *   "Admin can reorder zones by submitting the new sequence of zone ids; each zone's saved display order matches its position in the submitted list."
     */
    public function adminCanReorderZonesAndDisplayOrderMatchesPosition(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ShippingZoneFactory();
        $a = $factory->create(['name' => 'Reorder-A', 'order' => 50]);
        $b = $factory->create(['name' => 'Reorder-B', 'order' => 51]);
        $c = $factory->create(['name' => 'Reorder-C', 'order' => 52]);

        $newSequence = [(int) $c->id, (int) $a->id, (int) $b->id];

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones/update-order', [
            'zones' => $newSequence,
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, `order` FROM {$wpdb->prefix}fct_shipping_zones",
            ARRAY_A
        );
        $orderById = [];
        foreach ($rows as $r) {
            $orderById[(int) $r['id']] = (int) $r['order'];
        }
        Assert::assertSame(0, $orderById[(int) $c->id] ?? null, 'first id in submitted list must persist order=0');
        Assert::assertSame(1, $orderById[(int) $a->id] ?? null, 'second id in submitted list must persist order=1');
        Assert::assertSame(2, $orderById[(int) $b->id] ?? null, 'third id in submitted list must persist order=2');
    }

    /**
     * Spec: Cases/Functional/shipping.md [28]
     *   "Submitting an empty or non-array zone list to the reorder action is rejected with a clear error."
     */
    public function submittingEmptyOrNonArrayZoneListToReorderIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$emptyStatus, $emptyBody] = $I->post('/fluent-cart/v2/shipping/zones/update-order', [
            'zones' => [],
        ]);
        Assert::assertGreaterThanOrEqual(400, $emptyStatus, 'empty zones payload must return a non-2xx status. Body: ' . wp_json_encode($emptyBody));
        Assert::assertLessThan(500, $emptyStatus, 'empty zones payload must NOT 500 — it is a client validation error. Status: ' . $emptyStatus);
        Assert::assertNotEmpty($emptyBody['message'] ?? null, 'empty zones rejection must surface a clear message. Body: ' . wp_json_encode($emptyBody));

        [$stringStatus, $stringBody] = $I->post('/fluent-cart/v2/shipping/zones/update-order', [
            'zones' => 'not-an-array',
        ]);
        Assert::assertGreaterThanOrEqual(400, $stringStatus, 'non-array zones payload must return a non-2xx status. Body: ' . wp_json_encode($stringBody));
        Assert::assertLessThan(500, $stringStatus, 'non-array zones payload must NOT 500. Status: ' . $stringStatus);
        Assert::assertNotEmpty($stringBody['message'] ?? null, 'non-array zones rejection must surface a clear message. Body: ' . wp_json_encode($stringBody));
    }

    /**
     * Spec: Cases/Functional/shipping.md [29]
     *   "Submitting more than 200 zone ids in a single reorder request keeps only the first 200 and ignores the rest."
     */
    public function submittingMoreThan200ZoneIdsKeepsOnlyFirst200(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ShippingZoneFactory();

        $firstHundred = [];
        for ($i = 0; $i < 200; $i++) {
            $z = $factory->create(['name' => 'Reorder-Bulk-' . $i, 'order' => 1000 + $i]);
            $firstHundred[] = (int) $z->id;
        }
        $beyond = $factory->create(['name' => 'Reorder-Beyond-200', 'order' => 9999]);
        $beyondId = (int) $beyond->id;
        $originalBeyondOrder = 9999;

        $sequence = $firstHundred;
        $sequence[] = $beyondId;
        Assert::assertCount(201, $sequence, 'precondition: submitted sequence must hold exactly 201 ids');

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones/update-order', [
            'zones' => $sequence,
        ]);
        $I->seeResponseCodeIs(200);

        global $wpdb;
        $firstId    = $firstHundred[0];
        $lastInCap  = $firstHundred[199];
        $firstOrder = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT `order` FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d",
            $firstId
        ));
        $lastInCapOrder = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT `order` FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d",
            $lastInCap
        ));
        $beyondOrder = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT `order` FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d",
            $beyondId
        ));

        Assert::assertSame(0, $firstOrder, 'first id within the 200-cap must persist order=0');
        Assert::assertSame(199, $lastInCapOrder, '200th id (index 199) must persist order=199');
        Assert::assertSame(
            $originalBeyondOrder,
            $beyondOrder,
            'id beyond position 200 must retain its original order value (was not rewritten by the truncated reorder)'
        );
    }
}
