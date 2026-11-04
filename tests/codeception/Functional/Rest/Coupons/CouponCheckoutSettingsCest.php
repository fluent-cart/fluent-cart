<?php

namespace Tests\Functional\Rest\Coupons;

use FluentCart\App\Models\Meta;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Coupon checkout settings" section, items 52-53.
 *
 * Routes:
 *  - GET  /fluent-cart/v2/coupons/getSettings        (meta: coupons/view)
 *  - POST /fluent-cart/v2/coupons/storeCouponSettings (meta: coupons/manage)
 *
 * Controller:
 *  - getSettings reads the Meta row keyed by `fluent_cart_coupon_settings` and
 *    surfaces `show_on_checkout` for the admin UI's checkbox.
 *  - storeCouponSettings persists show_on_checkout as 1/0 in the same Meta row
 *    via FluentMetaResource::update.
 */
class CouponCheckoutSettingsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/coupons.md [53]
     *   "Admin can save the 'show coupon field on checkout' setting."
     */
    public function adminCanSaveShowCouponFieldOnCheckoutSetting(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        // Precondition: clean state — no fluent_cart_coupon_settings row before save, so
        // the post-save assertion is unambiguously a create-on-write effect, not a stale read.
        Assert::assertSame(
            0,
            (int) Meta::query()->where('meta_key', 'fluent_cart_coupon_settings')->count(),
            'precondition: no fluent_cart_coupon_settings row may exist before save'
        );

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/storeCouponSettings', [
            'show_on_checkout' => true,
        ]);

        Assert::assertSame(200, $status, 'storeCouponSettings must return 200. Body: ' . wp_json_encode($body));

        $row = Meta::query()->where('meta_key', 'fluent_cart_coupon_settings')->first();
        Assert::assertNotNull(
            $row,
            'storeCouponSettings must persist a fluent_cart_coupon_settings Meta row (create-on-write). Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            '1',
            (string) $row->getRawOriginal('meta_value'),
            'persisted meta_value must be the on-state sentinel "1" produced by Arr::get(...,"show_on_checkout") ? 1 : 0. Body: ' . wp_json_encode($body)
        );
    }
}
