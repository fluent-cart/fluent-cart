<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Managing package presets" section, items 76-91.
 *
 * Note: package presets are stored in the wp_options row keyed by 'shipping_packages'
 * via fluent_cart_get_option / fluent_cart_update_option. Each Cest method resets that
 * option in _before so methods are isolated.
 */
class PackagePresetsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $this->resetPackages();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->resetPackages();
    }

    private function resetPackages(): void
    {
        \fluent_cart_update_option('shipping_packages', []);
    }

    /**
     * Spec: Cases/Functional/shipping.md [76]
     *   "Admin can read the saved list of package presets, and the response is an empty list when none have been saved yet."
     */
    public function adminCanReadSavedListAndReceivesEmptyListWhenNoneSaved(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->get('/fluent-cart/v2/shipping/packages');

        $I->seeResponseCodeIs(200);
        Assert::assertSame([], (array) ($body['packages'] ?? null), 'packages list must be empty when no presets are saved');
    }

    /**
     * Spec: Cases/Functional/shipping.md [77]
     *   "Admin can save a list of package presets and the saved list is returned in the response."
     */
    public function adminCanSaveListOfPresetsAndSavedListIsReturned(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $payload = [
            ['slug' => 'small-box',  'name' => 'Small Box',  'type' => 'box',     'length' => 10, 'width' => 10, 'height' => 5,  'dimension_unit' => 'cm', 'weight' => 1, 'weight_unit' => 'kg'],
            ['slug' => 'big-box',    'name' => 'Big Box',    'type' => 'box',     'length' => 30, 'width' => 30, 'height' => 30, 'dimension_unit' => 'cm', 'weight' => 5, 'weight_unit' => 'kg'],
        ];

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', ['packages' => $payload]);
        $I->seeResponseCodeIs(200);

        $saved = (array) ($body['packages'] ?? []);
        Assert::assertCount(2, $saved, 'response must echo back the saved packages count');
        $names = array_map(static fn ($p) => (string) ($p['name'] ?? ''), $saved);
        Assert::assertContains('Small Box', $names, 'response must include Small Box');
        Assert::assertContains('Big Box', $names, 'response must include Big Box');

        $stored = (array) \fluent_cart_get_option('shipping_packages', []);
        Assert::assertCount(2, $stored, 'option store must hold 2 presets after save');
    }

    /**
     * Spec: Cases/Functional/shipping.md [78]
     *   "Saving more than 50 package presets keeps only the first 50 and discards the rest."
     */
    public function savingMoreThan50PresetsKeepsOnlyFirst50(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $payload = [];
        for ($i = 0; $i < 55; $i++) {
            $payload[] = [
                'slug'    => 'pkg-' . $i,
                'name'    => 'Pkg ' . $i,
                'type'    => 'box',
                'length'  => 1, 'width' => 1, 'height' => 1,
                'weight'  => 1,
            ];
        }

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', ['packages' => $payload]);
        $I->seeResponseCodeIs(200);

        $saved = (array) ($body['packages'] ?? []);
        Assert::assertCount(50, $saved, 'over-cap submission must be truncated to exactly 50 presets');

        $slugs = array_map(static fn ($p) => (string) ($p['slug'] ?? ''), $saved);
        Assert::assertContains('pkg-0', $slugs, 'first preset (index 0) must be kept');
        Assert::assertContains('pkg-49', $slugs, 'preset at index 49 must be kept');
        Assert::assertNotContains('pkg-50', $slugs, 'preset at index 50 must be discarded by the 50-cap');
        Assert::assertNotContains('pkg-54', $slugs, 'preset at index 54 must be discarded by the 50-cap');
    }

    /**
     * Spec: Cases/Functional/shipping.md [79]
     *   "Saving a packages payload that is not a list is treated as an empty list rather than rejected."
     */
    public function savingNonArrayPayloadIsTreatedAsEmptyList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', ['packages' => 'not-an-array']);
        $I->seeResponseCodeIs(200);
        Assert::assertSame([], (array) ($body['packages'] ?? null), 'non-array payload must be coerced to an empty list, not rejected');

        $stored = (array) \fluent_cart_get_option('shipping_packages', []);
        Assert::assertSame([], $stored, 'option store must hold zero presets after a non-array submit');
    }

    /**
     * Spec: Cases/Functional/shipping.md [80]
     *   "A box-type package preset preserves its height value."
     */
    public function boxTypePresetPreservesHeightValue(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['slug' => 'box-with-h', 'name' => 'BoxH', 'type' => 'box', 'length' => 5, 'width' => 5, 'height' => 12, 'weight' => 1],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $saved = (array) ($body['packages'][0] ?? []);
        Assert::assertSame(12.0, (float) ($saved['height'] ?? 0), 'box-type preset must preserve the supplied height value');
    }

    /**
     * Spec: Cases/Functional/shipping.md [81]
     *   "A soft-package-type package preset preserves its height value."
     */
    public function softPackageTypePresetPreservesHeightValue(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['slug' => 'soft-h', 'name' => 'SoftH', 'type' => 'soft_package', 'length' => 5, 'width' => 5, 'height' => 8, 'weight' => 1],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $saved = (array) ($body['packages'][0] ?? []);
        Assert::assertSame(8.0, (float) ($saved['height'] ?? 0), 'soft_package preset must preserve the supplied height value');
    }

    /**
     * Spec: Cases/Functional/shipping.md [82]
     *   "An envelope-type package preset stores no height value."
     */
    public function envelopeTypePresetStoresNoHeightValue(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['slug' => 'env-1', 'name' => 'Envelope1', 'type' => 'envelope', 'length' => 5, 'width' => 5, 'height' => 99, 'weight' => 0.1],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $stored = (array) \fluent_cart_get_option('shipping_packages', []);
        $row = (array) ($stored[0] ?? []);
        Assert::assertArrayHasKey('height', $row, 'envelope preset row must include a height key (set to null)');
        Assert::assertNull(
            $row['height'],
            'envelope preset must store height as null regardless of submitted value (submitted 99 must be discarded)'
        );
    }

    /**
     * Spec: Cases/Functional/shipping.md [83]
     *   "A package preset with an unknown box type defaults to \"box\"."
     */
    public function presetWithUnknownTypeDefaultsToBox(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['slug' => 'unknown-type', 'name' => 'Mystery', 'type' => 'crate-zzz', 'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $saved = (array) ($body['packages'][0] ?? []);
        Assert::assertSame('box', (string) ($saved['type'] ?? ''), 'unknown package type must default to "box"');
    }

    /**
     * Spec: Cases/Functional/shipping.md [84]
     *   "A package preset's dimension unit is preserved when it is centimetres, millimetres, inches, or metres."
     */
    public function packagePresetDimensionUnitPreservedForCmMmInM(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $units = ['cm', 'mm', 'in', 'm'];
        $payload = [];
        foreach ($units as $i => $u) {
            $payload[] = [
                'slug' => 'dim-' . $u,
                'name' => 'D-' . $u,
                'type' => 'box',
                'length' => 1, 'width' => 1, 'height' => 1,
                'dimension_unit' => $u,
                'weight' => 1,
            ];
        }

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', ['packages' => $payload]);
        $I->seeResponseCodeIs(200);

        $saved = (array) ($body['packages'] ?? []);
        $byName = [];
        foreach ($saved as $row) {
            $byName[(string) ($row['name'] ?? '')] = (string) ($row['dimension_unit'] ?? '');
        }
        foreach ($units as $u) {
            Assert::assertSame($u, $byName['D-' . $u] ?? '', "dimension_unit must be preserved as {$u}");
        }
    }

    /**
     * Spec: Cases/Functional/shipping.md [85]
     *   "A package preset with an unknown dimension unit defaults to centimetres."
     */
    public function packagePresetWithUnknownDimensionUnitDefaultsToCm(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['slug' => 'dim-bogus', 'name' => 'DimBogus', 'type' => 'box', 'length' => 1, 'width' => 1, 'height' => 1, 'dimension_unit' => 'leagues', 'weight' => 1],
            ],
        ]);
        $I->seeResponseCodeIs(200);
        $saved = (array) ($body['packages'][0] ?? []);
        Assert::assertSame('cm', (string) ($saved['dimension_unit'] ?? ''), 'unknown dimension_unit must default to cm');
    }

    /**
     * Spec: Cases/Functional/shipping.md [86]
     *   "A package preset's weight unit is preserved when it is kilograms, grams, pounds, or ounces."
     */
    public function packagePresetWeightUnitPreservedForKgGLbsOz(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $units = ['kg', 'g', 'lbs', 'oz'];
        $payload = [];
        foreach ($units as $u) {
            $payload[] = [
                'slug' => 'wt-' . $u,
                'name' => 'W-' . $u,
                'type' => 'box',
                'length' => 1, 'width' => 1, 'height' => 1,
                'weight' => 1,
                'weight_unit' => $u,
            ];
        }

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', ['packages' => $payload]);
        $I->seeResponseCodeIs(200);

        $saved = (array) ($body['packages'] ?? []);
        $byName = [];
        foreach ($saved as $row) {
            $byName[(string) ($row['name'] ?? '')] = (string) ($row['weight_unit'] ?? '');
        }
        foreach ($units as $u) {
            Assert::assertSame($u, $byName['W-' . $u] ?? '', "weight_unit must be preserved as {$u}");
        }
    }

    /**
     * Spec: Cases/Functional/shipping.md [87]
     *   "A package preset with an unknown weight unit defaults to kilograms."
     */
    public function packagePresetWithUnknownWeightUnitDefaultsToKg(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['slug' => 'wt-bogus', 'name' => 'WtBogus', 'type' => 'box', 'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1, 'weight_unit' => 'tons'],
            ],
        ]);
        $I->seeResponseCodeIs(200);
        $saved = (array) ($body['packages'][0] ?? []);
        Assert::assertSame('kg', (string) ($saved['weight_unit'] ?? ''), 'unknown weight_unit must default to kg');
    }

    /**
     * Spec: Cases/Functional/shipping.md [88]
     *   "A negative length, width, height, or weight value on a package preset is clamped up to zero before being stored."
     */
    public function negativeDimensionsAndWeightAreClampedToZero(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['slug' => 'neg-1', 'name' => 'NegPkg', 'type' => 'box', 'length' => -5, 'width' => -3, 'height' => -1, 'weight' => -10],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $saved = (array) ($body['packages'][0] ?? []);
        Assert::assertSame(0.0, (float) ($saved['length'] ?? -1), 'negative length must be clamped to 0');
        Assert::assertSame(0.0, (float) ($saved['width']  ?? -1), 'negative width must be clamped to 0');
        Assert::assertSame(0.0, (float) ($saved['height'] ?? -1), 'negative height must be clamped to 0');
        Assert::assertSame(0.0, (float) ($saved['weight'] ?? -1), 'negative weight must be clamped to 0');
    }

    /**
     * Spec: Cases/Functional/shipping.md [89]
     *   "Two package presets submitted with the same slug receive an auto-suffix so that each saved slug is unique."
     */
    public function duplicateSlugsReceiveAutoSuffixForUniqueness(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['slug' => 'duplicate-89', 'name' => 'First',  'type' => 'box', 'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1],
                ['slug' => 'duplicate-89', 'name' => 'Second', 'type' => 'box', 'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $saved = (array) ($body['packages'] ?? []);
        $slugs = array_map(static fn ($p) => (string) ($p['slug'] ?? ''), $saved);
        Assert::assertCount(2, $slugs, 'both submitted presets must be saved');
        Assert::assertCount(
            2,
            array_unique($slugs),
            'after auto-suffixing each saved slug must be unique. Slugs: ' . wp_json_encode($slugs)
        );
    }

    /**
     * Spec: Cases/Functional/shipping.md [90]
     *   "A package preset without a slug or a name receives an auto-generated unique slug."
     */
    public function presetWithoutSlugOrNameReceivesAutoGeneratedSlug(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['type' => 'box', 'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $saved = (array) ($body['packages'][0] ?? []);
        Assert::assertNotEmpty($saved['slug'] ?? '', 'auto-generated slug must be non-empty when neither slug nor name supplied');
    }

    /**
     * Spec: Cases/Functional/shipping.md [91]
     *   "A package preset name containing HTML or script tags is stored as sanitized plain text."
     */
    public function presetNameContainingHtmlIsStoredSanitized(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['slug' => 'hostile-91', 'name' => 'Hostile<script>alert(1)</script>91', 'type' => 'box', 'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $stored = (string) ($body['packages'][0]['name'] ?? '');
        Assert::assertStringNotContainsString('<script>', $stored, 'stored preset name must not retain raw <script> tag');
        Assert::assertStringContainsString('Hostile', $stored, 'sanitized name must preserve the plain-text part');
    }

    /**
     * max_weight (contents capacity) is persisted, defaults to 0 (unlimited)
     * when omitted, and a negative value clamps to 0.
     */
    public function packagePresetMaxWeightIsPersistedDefaultedAndClamped(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/packages', [
            'packages' => [
                ['slug' => 'maxw-set',     'name' => 'MaxWSet',     'type' => 'box', 'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1, 'max_weight' => 20],
                ['slug' => 'maxw-omitted', 'name' => 'MaxWOmitted', 'type' => 'box', 'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1],
                ['slug' => 'maxw-neg',     'name' => 'MaxWNeg',     'type' => 'box', 'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1, 'max_weight' => -8],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $byName = [];
        foreach ((array) ($body['packages'] ?? []) as $row) {
            $byName[(string) ($row['name'] ?? '')] = $row;
        }

        Assert::assertSame(20.0, (float) ($byName['MaxWSet']['max_weight'] ?? -1), 'max_weight must be persisted');
        Assert::assertSame(0.0, (float) ($byName['MaxWOmitted']['max_weight'] ?? -1), 'omitted max_weight must default to 0 (unlimited)');
        Assert::assertSame(0.0, (float) ($byName['MaxWNeg']['max_weight'] ?? -1), 'negative max_weight must clamp to 0');
    }
}
