<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsBulkUpdatePayloads;

/**
 * Bulk update must hold a variation to its value rules even when the
 * `required_if` condition that sits beside them is unmet.
 *
 * The framework validator dropped every rule on an attribute the moment its
 * `required_if` condition did not apply, so a one-time variation could carry
 * any string in `repeat_interval` and any number in `signup_fee`. The junk was
 * accepted with a 200 and written to `other_info`.
 */
class BulkUpdateConditionalValidationCest
{
    use BuildsBulkUpdatePayloads;

    public function bulkUpdateRejectsAnInvalidIntervalOnAOneTimeVariation(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BulkInterval']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', [
            'products' => [$this->bulkProductPayload($product, 'BulkInterval', [
                'payment_type'    => 'onetime',
                'repeat_interval' => 'BOGUS_INTERVAL',
            ])],
        ]);

        Assert::assertSame(
            422,
            $status,
            'an interval outside the enum must be rejected on a one-time variation too. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'variants.0.other_info.repeat_interval',
            (array) ($body['errors'][0]['fields'] ?? []),
            'the rejection must name the offending field so the bulk-edit grid can mark the cell. Body: ' . wp_json_encode($body)
        );

        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertNotSame(
            'BOGUS_INTERVAL',
            (string) ($variant->other_info['repeat_interval'] ?? ''),
            'a rejected interval must not reach other_info — the original defect returned success AND persisted the junk value'
        );
    }

    public function bulkUpdateRejectsANegativeSetupFeeWhileTheSetupFeeIsOff(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BulkNegativeFee']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', [
            'products' => [$this->bulkProductPayload($product, 'BulkNegativeFee', [
                'payment_type'     => 'onetime',
                'manage_setup_fee' => 'no',
                'signup_fee'       => -500,
            ])],
        ]);

        Assert::assertSame(
            422,
            $status,
            'a negative setup fee must be rejected whether or not the setup fee is switched on. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'variants.0.other_info.signup_fee',
            (array) ($body['errors'][0]['fields'] ?? []),
            'the rejection must name the setup fee field. Body: ' . wp_json_encode($body)
        );

        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertNotSame(
            -500,
            (int) ($variant->other_info['signup_fee'] ?? 0),
            'a rejected negative setup fee must not reach other_info'
        );
    }

    /**
     * `nullable` sits before the conditional check in
     * Validator::filterExcludeables() and drops every rule on a falsy value, so
     * `nullable|required_if:...` never demanded anything: a subscription could be
     * saved with no billing interval at all, and the storefront then had nothing
     * to renew on. The requirement is a closure now, and the attribute carries no
     * `nullable`.
     */
    public function bulkUpdateRejectsASubscriptionWithNoInterval(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BulkNoInterval']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', [
            'products' => [$this->bulkProductPayload($product, 'BulkNoInterval', [
                'payment_type'    => 'subscription',
                'repeat_interval' => '',
            ])],
        ]);

        Assert::assertSame(
            422,
            $status,
            'a subscription with no billing interval must be rejected. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'Interval is required for subscriptions.',
            (string) ($body['errors'][0]['fields']['variants.0.other_info.repeat_interval'] ?? ''),
            'the rejection must name the interval so the bulk grid can highlight the cell. Body: ' . wp_json_encode($body)
        );
    }

    public function bulkUpdateRejectsASetupFeeThatIsSwitchedOnWithNoAmount(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BulkFeeNoAmount']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', [
            'products' => [$this->bulkProductPayload($product, 'BulkFeeNoAmount', [
                'payment_type'     => 'subscription',
                'repeat_interval'  => 'monthly',
                'manage_setup_fee' => 'yes',
                'signup_fee'       => '',
                'signup_fee_name'  => 'Onboarding',
            ])],
        ]);

        Assert::assertSame(
            422,
            $status,
            'switching the setup fee on without an amount must be rejected. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'Setup Fee Amount is required.',
            (string) ($body['errors'][0]['fields']['variants.0.other_info.signup_fee'] ?? ''),
            'the rejection must name the setup fee amount. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * The counterpart the fix must not break: an empty interval on a ONE-TIME row
     * is normal stored state (36 variants on the dev store carry no setup-fee flag
     * at all, and one-time rows routinely store repeat_interval as ""), so it must
     * still save. Arming a requirement that rejected these would be the
     * findings-1-4 regression all over again.
     */
    public function bulkUpdateStillAcceptsAOneTimeRowWithAnEmptyInterval(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BulkOneTimeEmptyInterval']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', [
            'products' => [$this->bulkProductPayload($product, 'BulkOneTimeEmptyInterval', [
                'payment_type'    => 'onetime',
                'repeat_interval' => '',
            ])],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            [],
            (array) ($body['errors'] ?? []),
            'a one-time row with an empty interval must still save. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * manage_setup_fee is an optional flag both bulk services default to 'no', and
     * 36 of the dev store's variants carry no such key. Its requirement is
     * deliberately NOT armed — demanding it would reject every one of those rows.
     */
    public function bulkUpdateStillAcceptsASubscriptionWithNoSetupFeeFlag(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BulkNoFeeFlag']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', [
            'products' => [$this->bulkProductPayload($product, 'BulkNoFeeFlag', [
                'payment_type'    => 'subscription',
                'repeat_interval' => 'yearly',
            ])],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            [],
            (array) ($body['errors'] ?? []),
            'omitting the optional setup-fee flag must not be rejected. Body: ' . wp_json_encode($body)
        );
    }

    public function bulkUpdateStillAcceptsAValidOneTimeVariation(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BulkValidOneTime']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', [
            'products' => [$this->bulkProductPayload($product, 'BulkValidOneTime', [
                'payment_type'     => 'onetime',
                'repeat_interval'  => 'yearly',
                'manage_setup_fee' => 'no',
            ])],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            [],
            (array) ($body['errors'] ?? []),
            'arming the surviving rules must not reject a legitimate one-time row. Body: ' . wp_json_encode($body)
        );

        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(
            1000,
            (int) $variant->item_price,
            'the accepted row must still persist the price the payload carried'
        );
    }
}
