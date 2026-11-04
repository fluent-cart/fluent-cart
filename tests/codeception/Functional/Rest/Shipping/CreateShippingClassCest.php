<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Creating a shipping class" section, items 42-51.
 */
class CreateShippingClassCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [42]
     *   "Admin can create a fixed-cost shipping class."
     */
    public function adminCanCreateFixedCostShippingClass(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/classes', [
            'name' => 'FixedClass42',
            'cost' => 10,
            'type' => 'fixed',
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_class']['id'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'create response must return a non-zero class id');

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT name, type, cost FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d", $newId),
            ARRAY_A
        );
        Assert::assertSame('FixedClass42', (string) $row['name'], 'name must persist as supplied');
        Assert::assertSame('fixed', (string) $row['type'], 'type must persist as fixed');
        Assert::assertSame(10.0, (float) $row['cost'], 'cost must persist as supplied');
    }

    /**
     * Spec: Cases/Functional/shipping.md [43]
     *   "Admin can create a percentage-based shipping class."
     */
    public function adminCanCreatePercentageBasedShippingClass(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/classes', [
            'name' => 'PercentClass43',
            'cost' => 7.5,
            'type' => 'percentage',
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_class']['id'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'create response must return a non-zero class id');

        global $wpdb;
        $type = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT type FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $newId
        ));
        Assert::assertSame('percentage', $type, 'type must persist as percentage');
    }

    /**
     * Spec: Cases/Functional/shipping.md [44]
     *   "Creating a shipping class without a name is rejected with a clear error."
     */
    public function creatingShippingClassWithoutNameIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/classes', [
            'cost' => 5,
            'type' => 'fixed',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('name', (array) $body, 'validation payload must key the failed rule under name. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['name'] ?? []), 'name rejection must surface required rule key. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_classes");
        Assert::assertSame(0, $count, 'no class row may be created when name validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [45]
     *   "Creating a shipping class without a cost is rejected with a clear error."
     */
    public function creatingShippingClassWithoutCostIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/classes', [
            'name' => 'NoCost45',
            'type' => 'fixed',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('cost', (array) $body, 'validation payload must key the failed rule under cost. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['cost'] ?? []), 'cost rejection must surface required rule key. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_classes WHERE name = %s",
            'NoCost45'
        ));
        Assert::assertSame(0, $count, 'no class row may be created when cost validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [46]
     *   "Creating a shipping class with a negative cost is rejected with a clear error."
     */
    public function creatingShippingClassWithNegativeCostIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/classes', [
            'name' => 'NegativeCost46',
            'cost' => -10,
            'type' => 'fixed',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('cost', (array) $body, 'validation payload must key the failed rule under cost. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('min', (array) ($body['cost'] ?? []), 'negative cost rejection must surface min rule key. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_classes WHERE name = %s",
            'NegativeCost46'
        ));
        Assert::assertSame(0, $count, 'no class row may be created when negative-cost validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [47]
     *   "Creating a shipping class with a type other than fixed or percentage is rejected with a clear error."
     */
    public function creatingShippingClassWithInvalidTypeIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/classes', [
            'name' => 'InvalidType47',
            'cost' => 5,
            'type' => 'wibble',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('type', (array) $body, 'validation payload must key the failed rule under type. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('in', (array) ($body['type'] ?? []), 'invalid type rejection must surface in rule key. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_classes WHERE name = %s",
            'InvalidType47'
        ));
        Assert::assertSame(0, $count, 'no class row may be created when invalid-type validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [48]
     *   "Admin can create a shipping class with the per-item flag turned on."
     */
    public function adminCanCreateShippingClassWithPerItemFlagOn(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/classes', [
            'name'     => 'PerItemOn48',
            'cost'     => 5,
            'type'     => 'fixed',
            'per_item' => 1,
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_class']['id'] ?? 0);

        global $wpdb;
        $perItem = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT per_item FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $newId
        ));
        Assert::assertSame(1, $perItem, 'per_item flag must persist as 1');
    }

    /**
     * Spec: Cases/Functional/shipping.md [49]
     *   "Creating a shipping class with a description longer than 500 characters is rejected with a clear error."
     */
    public function creatingShippingClassWithDescriptionLongerThan500CharsIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $longDesc = str_repeat('x', 501);

        [, $body] = $I->post('/fluent-cart/v2/shipping/classes', [
            'name'        => 'LongDescriptionClass49',
            'cost'        => 5,
            'type'        => 'fixed',
            'description' => $longDesc,
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('description', (array) $body, 'validation payload must key the failed rule under description. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('maxLength', (array) ($body['description'] ?? []), 'description length rejection must surface maxLength rule key. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_classes WHERE name = %s",
            'LongDescriptionClass49'
        ));
        Assert::assertSame(0, $count, 'no class row may be created when description length validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [50]
     *   "A shipping class name containing HTML or script tags is stored as sanitized plain text."
     */
    public function shippingClassNameContainingHtmlIsStoredSanitized(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/classes', [
            'name' => 'HostileClass<script>alert(1)</script>50',
            'cost' => 5,
            'type' => 'fixed',
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_class']['id'] ?? 0);

        global $wpdb;
        $stored = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $newId
        ));
        Assert::assertStringNotContainsString('<script>', $stored, 'stored class name must not retain raw <script> tag');
        Assert::assertStringNotContainsString('</script>', $stored, 'stored class name must not retain closing </script> tag');
        Assert::assertStringContainsString('HostileClass', $stored, 'sanitized name must preserve the non-tag plain text');
    }

    /**
     * Spec: Cases/Functional/shipping.md [51]
     *   "A shipping class description containing block tags is stored as sanitized plain text."
     */
    public function shippingClassDescriptionContainingBlockTagsIsStoredSanitized(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/classes', [
            'name'        => 'DescriptionSanitizedClass51',
            'cost'        => 5,
            'type'        => 'fixed',
            'description' => '<div onclick="x()"><script>steal()</script>visible text</div>',
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_class']['id'] ?? 0);

        global $wpdb;
        $stored = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT description FROM {$wpdb->prefix}fct_shipping_classes WHERE id = %d",
            $newId
        ));
        Assert::assertStringNotContainsString('<script>', $stored, 'stored description must not retain raw <script> tag');
        Assert::assertStringContainsString('visible text', $stored, 'sanitized description must preserve the plain-text body');
    }
}
