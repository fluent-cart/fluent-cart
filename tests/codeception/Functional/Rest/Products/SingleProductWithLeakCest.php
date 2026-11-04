<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Http\Controllers\ProductController;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Security regression: GET products/{id} (ProductController::find) called
 * `$product->load($request->get('with'))` with the raw request value.
 *
 * `Product::orderItems()` is a real relation keyed on post_id, so an actor
 * holding nothing more than products/view could walk
 * `?with[]=orderItems.order.customer` from a catalogue product to order and
 * customer data — an earlier probe pulled 104 KB of it off a single product.
 *
 * The request value is now resolved through a two-tier allow-list on the
 * controller: the `block_product_detail` screen key the fourteen block editors
 * send, plus `detail` and `variants` as public keys. Every key is a literal
 * entry in that map — nothing is prefix-matched, suffix-stripped or decomposed —
 * and every callback carries its own products/view bar.
 */
class SingleProductWithLeakCest
{
    /**
     * Chains an actor must not be able to request off a catalogue product.
     */
    private const HOSTILE_WITHS = [
        'orderItems',
        'orderItems.order',
        'orderItems.order.customer',
        'orderItems.order.customer.wpUser',
        'downloadable_files',
        'licensesMeta',
        'postmeta',
    ];

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Seeds a real Product -> OrderItem -> Order -> Customer chain so every
     * hostile path has rows to resolve to. A test that cannot leak proves
     * nothing.
     */
    private function seedPurchasedProduct(string $email): int
    {
        $product = (new ProductFactory())->create();
        $customer = (new CustomerFactory())->create(['email' => $email]);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);

        // Product::orderItems() keys on post_id, not object_id.
        (new OrderItemFactory())->create((int) $order->id, [
            'post_id'   => $product->ID,
            'object_id' => $product->ID,
        ]);

        return (int) $product->ID;
    }

    public function hostileRelationChainsAreNotLoaded(FunctionalTester $I): void
    {
        $email = 'single-product-leak@example.test';
        $productId = $this->seedPurchasedProduct($email);

        $I->loginAsSuperAdmin();

        foreach (self::HOSTILE_WITHS as $with) {
            [$status, $body] = $I->get('/fluent-cart/v2/products/' . $productId, [
                'with' => [$with],
            ]);

            Assert::assertSame(
                200,
                $status,
                "products/{id} must still answer for with[]={$with}, not error"
            );

            $encoded = json_encode($body);

            Assert::assertStringNotContainsString(
                $email,
                $encoded,
                "with[]={$with} must not reach customer data from a catalogue product"
            );
            Assert::assertStringNotContainsString(
                'order_items',
                $encoded,
                "with[]={$with} must not attach order data to a catalogue product"
            );
        }
    }

    /**
     * A superadmin holds every permission, so this proves the guard is the
     * allow-list itself and not an incidental permission check.
     */
    public function columnSelectSuffixIsNotHonoured(FunctionalTester $I): void
    {
        $email = 'single-product-suffix@example.test';
        $productId = $this->seedPurchasedProduct($email);

        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $productId, [
            'with' => ['orderItems.order.customer:id,email'],
        ]);

        Assert::assertSame(200, $status);
        Assert::assertStringNotContainsString($email, json_encode($body));
    }

    /**
     * `detail` and `variants` are the two public keys. If this breaks, any
     * consumer naming a relation directly loses its data.
     */
    public function blockEditorRelationsStillLoad(FunctionalTester $I): void
    {
        $productId = $this->seedPurchasedProduct('single-product-shape@example.test');

        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $productId, [
            'with' => ['detail', 'variants'],
        ]);

        Assert::assertSame(200, $status);

        $product = $body['product'] ?? [];

        Assert::assertArrayHasKey('detail', $product, 'ProductGallery/PriceRange read through detail');
        Assert::assertArrayHasKey('variants', $product, 'BuySection/ProductSku read through variants');
    }

    /**
     * The screen key the fourteen block editors send now. It must resolve to the
     * detail row plus the variation rows — the union of what those blocks render
     * — and to nothing beyond it.
     */
    public function screenKeyLoadsWhatTheBlockEditorsRender(FunctionalTester $I): void
    {
        $productId = $this->seedPurchasedProduct('single-product-screen-key@example.test');

        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $productId, [
            'with' => ['block_product_detail'],
        ]);

        Assert::assertSame(200, $status);

        $product = (array) ($body['product'] ?? []);

        Assert::assertArrayHasKey('detail', $product, 'block_product_detail must eager-load detail');
        Assert::assertArrayHasKey('variants', $product, 'block_product_detail must eager-load variants');

        Assert::assertStringNotContainsString(
            'single-product-screen-key@example.test',
            json_encode($body),
            'block_product_detail must not widen into order or customer data'
        );
        Assert::assertArrayNotHasKey(
            'order_items',
            $product,
            'block_product_detail must not reach orderItems'
        );
    }

    /**
     * Each public key resolves on its own, and neither drags the other in.
     */
    public function eachPublicKeyResolvesOnItsOwn(FunctionalTester $I): void
    {
        $productId = $this->seedPurchasedProduct('single-product-public-keys@example.test');

        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $productId, [
            'with' => ['detail'],
        ]);
        Assert::assertSame(200, $status);
        Assert::assertArrayHasKey('detail', (array) ($body['product'] ?? []), 'with[]=detail must eager-load detail');
        Assert::assertArrayNotHasKey('variants', (array) ($body['product'] ?? []), 'with[]=detail must not also load variants');

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $productId, [
            'with' => ['variants'],
        ]);
        Assert::assertSame(200, $status);
        Assert::assertArrayHasKey('variants', (array) ($body['product'] ?? []), 'with[]=variants must eager-load variants');
        // No `detail` assertion in this direction: Product::$appends carries
        // `thumbnail`, which resolves through `detail->featured_media` and
        // lazy-loads `detail` onto the model for EVERY product response. That
        // append predates the allow-list and is not something `with` controls.
    }

    /**
     * `product_menu` is a controller sentinel, not a relation: find() reads it
     * off the raw request and answers it with AdminHelper::getProductMenu(). It
     * must keep working alongside a screen key, and it must never become an
     * eager load.
     */
    public function productMenuSentinelStillAnswers(FunctionalTester $I): void
    {
        $productId = $this->seedPurchasedProduct('single-product-menu@example.test');

        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $productId, [
            'with' => ['block_product_detail', 'product_menu'],
        ]);

        Assert::assertSame(200, $status);
        Assert::assertArrayHasKey('product_menu', (array) $body, 'the product_menu sentinel must still be answered');

        $product = (array) ($body['product'] ?? []);
        Assert::assertArrayHasKey('detail', $product, 'the sentinel must not displace the screen key');
        Assert::assertArrayNotHasKey('product_menu', $product, 'product_menu must never be eager-loaded as a relation');
    }

    /**
     * Every entry on the map consults PermissionManager and refuses a caller
     * holding nothing.
     *
     * Exercised through the map rather than over HTTP: this suite loads only the
     * free plugin, so PermissionManager::getUserPermissions() short-circuits on
     * `!App::isProActive()` and returns [] for every non-administrator, which
     * means the route's own products/view denies them before find() runs. The
     * anonymous caller is the one state that reaches every callback — and the
     * real cron/WP-CLI case, where the same map runs with no current user.
     */
    public function everyEntryRefusesACallerHoldingNothing(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $everyKey = ['block_product_detail', 'detail', 'variants'];

        $controller = new ProductController();
        $resolve    = new \ReflectionMethod($controller, 'resolveEagerLoads');
        $resolve->setAccessible(true);

        foreach ($everyKey as $key) {
            Assert::assertNotEmpty(
                $resolve->invoke($controller, [$key]),
                "baseline: with[]={$key} must resolve to at least one relation for a permitted caller"
            );
        }

        $I->logout();

        foreach ($everyKey as $key) {
            Assert::assertSame(
                [],
                $resolve->invoke($controller, [$key]),
                "with[]={$key} must contribute nothing when the caller holds no permission — the gate lives in the callback, not in the route"
            );
        }
    }

    /**
     * An unknown key degrades to a bare product rather than a 500. The ORM turns
     * an unknown relation into RelationNotFoundException, so a stale block build
     * must be dropped silently, not hard-failed.
     */
    public function unknownKeyDegradesInsteadOfErroring(FunctionalTester $I): void
    {
        $productId = $this->seedPurchasedProduct('single-product-unknown@example.test');

        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $productId, [
            'with' => ['thisKeyDoesNotExist'],
        ]);

        Assert::assertSame(200, $status);
        Assert::assertSame(
            $productId,
            (int) ($body['product']['ID'] ?? 0),
            'an unknown key must degrade to a normal product payload'
        );
    }

    /**
     * The nested-array shape the ORM flattens into `orderItems` +
     * `orderItems.order.customer`. Only string request entries are considered, so
     * the key is never read and the whole entry is dropped.
     */
    public function nestedArrayShapeIsDropped(FunctionalTester $I): void
    {
        $email     = 'single-product-nested@example.test';
        $productId = $this->seedPurchasedProduct($email);

        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $productId, [
            'with' => ['orderItems' => ['order.customer']],
        ]);

        Assert::assertSame(200, $status, 'the nested-array shape must degrade, not error');

        $encoded = json_encode($body);
        Assert::assertStringNotContainsString($email, $encoded, 'the nested-array shape must not reach customer data');
        Assert::assertStringNotContainsString('order_items', $encoded, 'the nested-array shape must not reach order data');
    }
}
