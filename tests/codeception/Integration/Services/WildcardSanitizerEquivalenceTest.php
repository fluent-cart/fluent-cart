<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Helper as ApiHelper;
use FluentCart\App\Http\Controllers\TaxConfigurationController;
use FluentCart\App\Http\Requests\ProductDownloadable\ProductDownloadableBulkFileRequest;
use FluentCart\App\Http\Requests\ProductUpdateRequest;
use FluentCart\Framework\Support\Sanitizer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Traits\InvokesPrivateMethods;
use Tests\Support\Traits\ReplicatesHandRolledSanitizers;

/**
 * Differential harness: run FluentCart's hand-rolled sanitize callbacks and the
 * equivalent framework wildcard map over the SAME payload, then compare.
 *
 * The point is to decide, per call site, whether "replace the loop with a
 * wildcard map" is a safe swap. Three of them are; three are not, and the ones
 * that are not would lose or destroy data. Every expectation below was observed
 * by running both paths — none is assumed.
 *
 * These are characterization tests: they pin what the two paths do TODAY. A
 * divergence assertion is not an endorsement of the divergence — it is a
 * tripwire, so that fixing the underlying map has to update the test on purpose.
 */
class WildcardSanitizerEquivalenceTest extends WPTestCase
{
    use InvokesPrivateMethods;
    use ReplicatesHandRolledSanitizers;

    // =========================================================================
    // SAFE SWAPS — hand-rolled loop and wildcard map agree exactly
    // =========================================================================

    public function test_order_item_wildcard_map_through_get_safe_matches_hand_rolled_loop(): void
    {
        $rawItems = [
            ['post_id' => '5', 'object_id' => '9', 'subtotal' => '1999',
             'discount_total' => '0', 'shipping_charge' => '500', 'quantity' => '3'],
            ['post_id' => '6', 'object_id' => '11', 'subtotal' => '250',
             'discount_total' => '50', 'shipping_charge' => '0', 'quantity' => '0'],
        ];

        $legacy = $this->handRolledTaxItems($rawItems);

        $request = fluentCart()->request;
        $request->merge(['items' => $rawItems]);
        $wildcard = $request->getSafe([
            'items.*.post_id'         => 'intval',
            'items.*.object_id'       => 'intval',
            'items.*.subtotal'        => 'intval',
            'items.*.discount_total'  => 'intval',
            'items.*.shipping_charge' => 'intval',
            'items.*.quantity'        => function ($value) {
                return max(1, (int) $value);
            },
        ])['items'];

        $this->assertSame(
            $legacy,
            $wildcard,
            'OrderController::calculateTax item loop and the wildcard map must produce identical items; '
            . 'this is the safe conversion, including the quantity floor of 1.'
        );
    }

    public function test_tax_nested_sanitizer_matches_an_explicit_wildcard_map(): void
    {
        $settings = [
            'eu_vat_settings' => [
                'enabled'  => 'yes',
                'rate'     => '19.5',
                'excluded' => ['3', '7'],
                'label'    => '<b>VAT</b>',
            ],
        ];

        $legacy = $this->invokePrivateMethod(
            TaxConfigurationController::class,
            'sanitizeNestedArray',
            [$settings]
        );

        $wildcard = Sanitizer::sanitize($settings, [
            'eu_vat_settings.enabled'    => 'sanitize_text_field',
            'eu_vat_settings.rate'       => 'floatval',
            'eu_vat_settings.excluded.*' => 'intval',
            'eu_vat_settings.label'      => 'sanitize_text_field',
        ]);

        $this->assertSame(
            $legacy,
            $wildcard,
            'TaxConfigurationController::sanitizeNestedArray must be replaceable by an explicit '
            . 'wildcard map with no behaviour change, including its numeric-string coercion.'
        );
    }

    public function test_sanitize_text_all_matches_a_trailing_wildcard_map(): void
    {
        $payload = ['to_variants' => ['3', '4', '<b>5</b>'], 'title' => '<i>Up</i>'];

        $legacy = ApiHelper::sanitizeTextAll($payload);

        $wildcard = Sanitizer::sanitize($payload, [
            'to_variants.*' => 'sanitize_text_field',
            'title'         => 'sanitize_text_field',
        ]);

        $this->assertSame(
            $legacy,
            $wildcard,
            'Helper::sanitizeTextAll must be replaceable by to_variants.* + title when the '
            . 'wildcard map uses the same sanitizer it applies.'
        );
    }

    // =========================================================================
    // UNSAFE SWAPS — the wildcard map loses, retypes, fabricates, or fatals
    // =========================================================================

    public function test_raw_sanitizer_without_get_safe_leaks_unmapped_keys(): void
    {
        $rawItems = [['post_id' => '5', 'quantity' => '3', 'evil' => '<script>alert(1)</script>']];

        $wildcardOnly = Sanitizer::sanitize(
            ['items' => $rawItems],
            ['items.*.post_id' => 'intval', 'items.*.quantity' => 'intval']
        )['items'][0];

        $this->assertArrayHasKey(
            'evil',
            $wildcardOnly,
            'Sanitizer::sanitize() alone only rewrites mapped keys — it is NOT an allow-list, so an '
            . 'unmapped key survives untouched. Conversions must go through getSafe(), whose '
            . 'pickKeys() step is what drops it.'
        );

        $request = fluentCart()->request;
        $request->merge(['items' => $rawItems]);
        $throughGetSafe = $request->getSafe(
            ['items.*.post_id' => 'intval', 'items.*.quantity' => 'intval']
        )['items'][0];

        $this->assertArrayNotHasKey(
            'evil',
            $throughGetSafe,
            'getSafe() must drop the unmapped key; this is the property that makes wildcard '
            . 'sanitize maps safer than the hand-rolled loops.'
        );
    }

    public function test_downloadable_files_wildcard_map_destroys_the_variation_id_array(): void
    {
        $row = [
            'id' => '7', 'post_id' => '11', 'product_variation_id' => ['3', '4'],
            'title' => '<b>Manual</b>', 'serial' => '2',
        ];

        $legacy = $this->handRolledDownloadableFiles([$row])[0];

        $this->assertSame(
            [3, 4],
            $legacy['product_variation_id'],
            'The hand-rolled loop int-casts each variation id and keeps the array.'
        );

        $wildcard = Sanitizer::sanitize(
            ['downloadable_files' => [$row]],
            ['downloadable_files.*.product_variation_id' => 'sanitize_text_field']
        )['downloadable_files'][0];

        $this->assertSame(
            '',
            $wildcard['product_variation_id'],
            'ProductDownloadableBulkFileRequest::sanitize() maps product_variation_id to '
            . 'sanitize_text_field, but the value is an ARRAY — WordPress returns "" for that, '
            . 'silently destroying every variation link. Adopting this map as-is loses data.'
        );
    }

    public function test_downloadable_files_wildcard_map_fatals_on_the_settings_array(): void
    {
        $row = ['settings' => ['download_limit' => '5', 'download_expiry' => '30']];

        $legacy = $this->handRolledDownloadableFiles([$row])[0];

        $this->assertSame(
            ['download_limit' => '5', 'download_expiry' => '30'],
            $legacy['settings'],
            'The hand-rolled loop passes settings through untouched.'
        );

        $this->expectException(\TypeError::class);

        Sanitizer::sanitize(
            ['downloadable_files' => [$row]],
            ['downloadable_files.*.settings' => 'wp_kses_post']
        );
    }

    public function test_downloadable_files_wildcard_map_drops_post_id_and_retypes_serial(): void
    {
        $row = ['id' => '7', 'post_id' => '11', 'serial' => '2', 'title' => 'Manual'];

        $legacy = $this->handRolledDownloadableFiles([$row])[0];
        $map    = (new ProductDownloadableBulkFileRequest())->sanitize();

        $this->assertArrayNotHasKey(
            'downloadable_files.*.post_id',
            $map,
            'post_id is commented out of the sanitize map, so getSafe() would drop it — but the '
            . 'hand-rolled loop int-casts and keeps it.'
        );
        $this->assertSame(11, $legacy['post_id'], 'The loop keeps post_id as an int.');

        $this->assertSame(
            'intval',
            $map['downloadable_files.*.serial'],
            'The map int-casts serial...'
        );
        $this->assertSame(
            '2',
            $legacy['serial'],
            '...while the loop sanitize_text_field()s it, so the swap silently changes the stored type.'
        );
    }

    public function test_wildcard_sanitize_map_fabricates_null_keys_on_a_partial_update(): void
    {
        // A PATCH-style payload: the client edits ONLY item_price on one variant.
        $request = fluentCart()->request;
        $request->merge(['variants' => null]);
        $request->merge(['variants' => [['id' => 12, 'item_price' => '2500']]]);

        $productUpdateRequest = new ProductUpdateRequest();
        $productUpdateRequest->setRequestInstance($request);

        $legacy = $request->getSafe($productUpdateRequest->sanitize())['variants'][0];

        $this->assertSame(
            ['id', 'item_price'],
            array_keys($legacy),
            'ProductUpdateRequest builds per-index sanitize keys guarded by Arr::has(), so only the '
            . 'fields the client actually sent come back. That guard is deliberate PATCH semantics.'
        );

        $naiveWildcard = $request->getSafe([
            'variants.*.id'               => 'intval',
            'variants.*.item_price'       => 'intval',
            'variants.*.variation_title'  => 'sanitize_text_field',
            'variants.*.sku'              => 'sanitize_text_field',
            'variants.*.stock_status'     => 'sanitize_text_field',
        ])['variants'][0];

        $this->assertArrayHasKey('sku', $naiveWildcard, 'The naive rewrite fabricates a key...');
        $this->assertNull(
            $naiveWildcard['sku'],
            '...whose value is null. pickKeys() fills every MAPPED-but-ABSENT explicit leaf with the '
            . 'default, so rewriting ProductUpdateRequest to wildcards would write NULL over stored '
            . 'sku / variation_title / stock_status on a price-only edit. Do not convert this one.'
        );
    }
}
