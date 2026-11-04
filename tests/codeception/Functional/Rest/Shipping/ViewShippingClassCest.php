<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Viewing a single shipping class" section, items 40-41.
 */
class ViewShippingClassCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [40]
     *   "Admin can open a shipping class by id and see the class's name, description, cost, type, and per-item flag."
     */
    public function adminCanOpenShippingClassByIdAndSeeAllAttributes(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class = (new ShippingClassFactory())->create([
            'name'        => 'ViewClass40',
            'description' => 'A class for viewing.',
            'cost'        => 17.95,
            'type'        => 'percentage',
            'per_item'    => 1,
        ]);

        [, $body] = $I->get('/fluent-cart/v2/shipping/classes/' . (int) $class->id);

        $I->seeResponseCodeIs(200);
        $payload = (array) ($body['shipping_class'] ?? []);
        Assert::assertSame((int) $class->id, (int) ($payload['id'] ?? 0), 'view class must echo the requested class id');
        Assert::assertSame('ViewClass40', (string) ($payload['name'] ?? ''), 'view class must include name');
        Assert::assertSame('A class for viewing.', (string) ($payload['description'] ?? ''), 'view class must include description');
        Assert::assertSame(17.95, (float) ($payload['cost'] ?? 0), 'view class must include cost');
        Assert::assertSame('percentage', (string) ($payload['type'] ?? ''), 'view class must include type');
        Assert::assertSame(1, (int) ($payload['per_item'] ?? 0), 'view class must include per_item flag');
    }

    /**
     * Spec: Cases/Functional/shipping.md [41]
     *   "Opening a shipping class that does not exist returns a clear \"not found\" error."
     */
    public function openingNonExistentShippingClassReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/shipping/classes/999999');

        Assert::assertGreaterThanOrEqual(400, $status, 'opening missing class must be a non-2xx status. Body: ' . wp_json_encode($body));
        Assert::assertLessThan(500, $status, 'opening missing class must NOT 500. Status: ' . $status);
        Assert::assertEmpty($body['shipping_class'] ?? null, 'response must NOT echo a shipping_class payload for a missing id');
    }
}
