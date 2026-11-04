<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\App;
use FluentCart\App\Hooks\Handlers\ShortCodes\ReceiptHandler;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use FluentCart\Framework\Support\Arr;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Guest-reachable checkout paths must not expose unpublished catalogue data,
 * sell unpublished products, accept item data no add-on vouched for, or show
 * another customer's stored name on a guest's receipt.
 */
class FrontendExposureProbeCest
{
    use PlacesCheckoutOrders;

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->removeStubCheckoutGateway();
        $I->logout();
    }

    public function productModalDoesNotRenderADraftProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withAttributes(['post_status' => 'draft', 'post_title' => 'Secret Draft Product 4471'])->create();
        $cart = $this->buildInstantCart($I, [[(new ProductFactory())->create()->variants->first(), 1]]);

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'get_product_modal_view',
            'fct_cart_hash'      => $cart->cart_hash,
            'product_id'         => $product->ID,
        ]);

        Assert::assertStringNotContainsString('Secret Draft Product 4471', wp_json_encode($body), 'a guest must not see a draft product through the product modal');        Assert::assertSame(422, $status, 'a refused product modal answers 422');
        Assert::assertSame('Product not found', $body['message'] ?? null);
    }

    public function productModalDoesNotRenderAPrivateProductToAGuest(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withAttributes(['post_status' => 'private', 'post_title' => 'Secret Private Product 4472'])->create();
        $cart = $this->buildInstantCart($I, [[(new ProductFactory())->create()->variants->first(), 1]]);

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'get_product_modal_view',
            'fct_cart_hash'      => $cart->cart_hash,
            'product_id'         => $product->ID,
        ]);

        Assert::assertStringNotContainsString('Secret Private Product 4472', wp_json_encode($body), 'a guest must not see a private product through the product modal');        Assert::assertSame(422, $status, 'a refused product modal answers 422');
    }

    public function productModalRendersAPrivateProductForAnAdmin(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withAttributes(['post_status' => 'private', 'post_title' => 'Admin Private Product 4474'])->create();
        $cart = $this->buildInstantCart($I, [[(new ProductFactory())->create()->variants->first(), 1]]);
        $I->loginAsSuperAdmin();

        [, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'get_product_modal_view',
            'fct_cart_hash'      => $cart->cart_hash,
            'product_id'         => $product->ID,
        ]);

        Assert::assertStringContainsString('Admin Private Product 4474', wp_json_encode($body), 'a user who can read private posts still sees a private product');
    }

    // Guards the visibility check against hiding the normal case.
    public function productModalStillRendersAPublishedProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withAttributes(['post_status' => 'publish', 'post_title' => 'Visible Product 4473'])->create();
        $cart = $this->buildInstantCart($I, [[(new ProductFactory())->create()->variants->first(), 1]]);

        [, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'get_product_modal_view',
            'fct_cart_hash'      => $cart->cart_hash,
            'product_id'         => $product->ID,
        ]);

        Assert::assertStringContainsString('Visible Product 4473', wp_json_encode($body), 'a published product still renders in the product modal');
    }

    public function placeOrderRejectsADraftProduct(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withAttributes(['post_status' => 'draft'])->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertNotSame('success', (string) ($body['status'] ?? ''), 'an unpublished product must not be purchasable. Body: ' . wp_json_encode($body));
        Assert::assertSame(0, Order::query()->count(), 'no order for an unpublished product');
    }

    public function cartUpdateIgnoresACustomItemNoAddonHandles(FunctionalTester $I): void
    {
        $cart = $this->buildInstantCart($I, [[(new ProductFactory())->create()->variants->first(), 1]]);
        $before = count((array) $cart->cart_data);

        $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => 987654,
            'quantity'           => 1,
            'is_custom'          => 'true',
        ]);

        $after = (array) Cart::query()->where('cart_hash', $cart->cart_hash)->first()->cart_data;
        Assert::assertCount($before, $after, 'an unhandled custom item must not be added to the cart. Cart: ' . wp_json_encode($after));
    }

    public function guestReceiptDoesNotShowTheStoredNameOfTheEmailsCustomer(FunctionalTester $I): void
    {
        (new CustomerFactory())->create(['email' => 'named-buyer@example.test', 'first_name' => 'Zebulon', 'last_name' => 'Quartermaine']);
        $cart = $this->buildInstantCart($I, [[(new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first(), 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_email'      => 'named-buyer@example.test',
            'billing_full_name'  => 'Some Stranger',
            'billing_first_name' => 'Some',
            'billing_last_name'  => 'Stranger',
        ]));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'precondition: the guest order is accepted. Body: ' . wp_json_encode($body));

        $trx = OrderTransaction::query()->orderBy('id', 'DESC')->first();
        $app = App::getInstance();
        $request = new FluentRequest($app, ['trx_hash' => $trx->uuid], []);
        $app->instance(FluentRequest::class, $request);
        $app->instance('request', $request);
        $html = (new ReceiptHandler())->renderRedirectPage(['type' => 'receipt']);

        Assert::assertStringContainsString('Some', $html, 'precondition: the receipt rendered');
        Assert::assertStringNotContainsString('Quartermaine', $html, "the guest's receipt must not show the stored name of the customer who owns that email");
    }
}
