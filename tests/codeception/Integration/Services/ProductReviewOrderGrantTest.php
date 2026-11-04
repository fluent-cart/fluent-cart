<?php

namespace Tests\Integration\Services;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\OrderReviewRenderer;
use FluentCart\App\App;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\WPUserFactory;

/**
 * The order-hash grant: the rule that lets someone holding an order's uuid
 * review what that order contains, without logging in.
 *
 * Spec: Cases/reviews.md — "Reviewing from an order link"
 */
class ProductReviewOrderGrantTest extends WPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // verified_buyers is the shipped default and the mode the grant has to
        // work around, so every case here runs under it explicitly rather than
        // inheriting whatever a previous test left behind.
        ModuleSettings::saveSettings([
            'reviews' => [
                'active'                 => 'yes',
                'review_permission_mode' => Status::REVIEW_PERMISSION_VERIFIED_BUYERS,
                'auto_approve_reviews'   => 'no',
            ],
        ]);
        // ModuleSettings memoizes in a function-static; passing false re-reads
        // the option and re-primes it.
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        wp_set_current_user(0);

        // canSubmitReview() reads the live request; clear the keys it looks at
        // so nothing leaks between cases.
        App::request()->set('order_hash', null);
        App::request()->set('reviewer_email', null);
    }

    protected function tearDown(): void
    {
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    /**
     * Spec: Cases/reviews.md [1]
     *   "An order hash unlocks a product that order contains."
     */
    public function test_resolve_order_grant_returns_the_order_for_a_product_on_it(): void
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        $grant = ProductReviewService::resolveOrderGrant($product->ID, $order->uuid);

        $this->assertNotNull($grant, 'A hash naming an order that contains the product must resolve');
        $this->assertSame((int) $order->id, (int) $grant->id, 'The grant must be the order the hash names');
    }

    /**
     * Spec: Cases/reviews.md [2]
     *   "An order hash does not unlock a product that order does not contain."
     */
    public function test_resolve_order_grant_returns_null_for_a_product_absent_from_the_order(): void
    {
        $customer = (new CustomerFactory())->create();
        $bought = (new ProductFactory())->create();
        $notBought = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $bought->ID]);

        $grant = ProductReviewService::resolveOrderGrant($notBought->ID, $order->uuid);

        $this->assertNull($grant, 'A hash must not unlock the whole catalogue, only what the order contains');
    }

    /**
     * Spec: Cases/reviews.md [2b]
     *   "A hash for an order that never completed unlocks nothing."
     */
    public function test_resolve_order_grant_returns_null_for_an_order_that_never_completed(): void
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();

        // An order row and its uuid both exist before payment, so without a
        // status check anyone could start a checkout, abandon it, and hold a
        // working grant for everything in the basket.
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_ON_HOLD,
        ]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        $this->assertNull(
            ProductReviewService::resolveOrderGrant($product->ID, $order->uuid),
            'An abandoned or unpaid checkout must not mint a usable review grant'
        );

        App::request()->set('order_hash', $order->uuid);
        $result = ProductReviewService::canSubmitReview($product->ID, App::request());
        $this->assertFalse($result['can_submit'], 'And the submission it would authorise must be refused');
    }

    /**
     * Spec: Cases/reviews.md [3]
     *   "An unknown or empty order hash unlocks nothing."
     */
    public function test_resolve_order_grant_returns_null_for_an_unknown_or_empty_hash(): void
    {
        $product = (new ProductFactory())->create();

        $this->assertNull(
            ProductReviewService::resolveOrderGrant($product->ID, 'NOSUCHORDERHASH'),
            'A hash matching no order must not resolve'
        );
        $this->assertNull(
            ProductReviewService::resolveOrderGrant($product->ID, ''),
            'An empty hash must not resolve'
        );
    }

    /**
     * Spec: Cases/reviews.md [4]
     *   "A grant posts under the order customer's name and email."
     */
    public function test_order_grant_identity_comes_from_the_order_customer(): void
    {
        $customer = (new CustomerFactory())->create([
            'first_name' => 'Ada',
            'last_name'  => 'Lovelace',
            'email'      => 'ada@example.com',
        ]);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);

        $identity = ProductReviewService::orderGrantIdentity($order);

        $this->assertSame('Ada Lovelace', $identity['name'], 'The byline is the order customer');
        $this->assertSame('ada@example.com', $identity['email'], 'The email is the order customer');
        $this->assertSame((int) $customer->id, (int) $identity['customer_id'], 'The review is attributed to the customer');
    }

    /**
     * Spec: Cases/reviews.md [5]
     *   "A nameless customer still gets a byline rather than a blank one — a neutral label, not half their email address."
     */
    public function test_order_grant_identity_falls_back_to_a_neutral_label_when_unnamed(): void
    {
        $customer = (new CustomerFactory())->create([
            'first_name' => '',
            'last_name'  => '',
            'email'      => 'grace.hopper1906@example.com',
        ]);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);

        $identity = ProductReviewService::orderGrantIdentity($order);

        $this->assertNotSame('', $identity['name'], 'A blank name would fail the required-name check on submit');
        $this->assertStringNotContainsString('grace', strtolower($identity['name']), 'The byline is published; it must not carry the email local part');
        $this->assertSame('Verified Buyer', $identity['name']);
    }

    /**
     * Spec: Cases/reviews.md [6]
     *   "A logged-out visitor with an order hash may review, in verified buyers mode."
     */
    public function test_can_submit_review_allows_a_logged_out_visitor_holding_a_grant(): void
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        App::request()->set('order_hash', $order->uuid);
        $result = ProductReviewService::canSubmitReview($product->ID, App::request());

        $this->assertTrue($result['can_submit'], 'The hash is proof of purchase: ' . $result['message']);
    }

    /**
     * Spec: Cases/reviews.md [7]
     *   "A logged-out visitor without an order hash may not review, in verified buyers mode."
     */
    public function test_can_submit_review_still_rejects_a_logged_out_visitor_with_no_grant(): void
    {
        $product = (new ProductFactory())->create();

        $result = ProductReviewService::canSubmitReview($product->ID, App::request());

        $this->assertFalse($result['can_submit'], 'Without a grant the permission mode must still apply');
    }

    /**
     * Spec: Cases/reviews.md [8]
     *   "An order hash does not let its holder review a product from another order."
     */
    public function test_can_submit_review_rejects_a_grant_for_a_product_absent_from_the_order(): void
    {
        $customer = (new CustomerFactory())->create();
        $bought = (new ProductFactory())->create();
        $notBought = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $bought->ID]);

        App::request()->set('order_hash', $order->uuid);
        $result = ProductReviewService::canSubmitReview($notBought->ID, App::request());

        $this->assertFalse($result['can_submit'], 'A grant is scoped to the order it names, not to the store');
    }

    /**
     * Spec: Cases/reviews.md [9]
     *   "A second review is refused even when the visitor supplies a different email."
     */
    public function test_duplicate_guard_keys_on_the_order_customer_not_the_supplied_email(): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'buyer@example.com']);
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        ProductReview::query()->create([
            'post_id'        => $product->ID,
            'rating'         => 5,
            'title'          => 'First',
            'content'        => 'Already reviewed this one.',
            'status'         => Status::REVIEW_PENDING,
            'user_id'        => 0,
            'customer_id'    => $customer->id,
            'order_id'       => $order->id,
            'reviewer_name'  => 'Buyer',
            'reviewer_email' => 'buyer@example.com',
            'is_verified'    => 0,
        ]);

        // A different address on the request must not open a second slot: the
        // guard reads the order's customer, which is what the submit path
        // writes regardless of what the client sends.
        App::request()->set('order_hash', $order->uuid);
        App::request()->set('reviewer_email', 'someone-else@example.com');
        $result = ProductReviewService::canSubmitReview($product->ID, App::request());

        $this->assertFalse($result['can_submit'], 'Changing the typed email must not dodge the duplicate guard');
    }

    /**
     * Spec: Cases/reviews.md [1b]
     *   "One order link is looked up once per request, however many products it is asked about."
     */
    public function test_resolve_order_grant_loads_the_order_once_for_every_product_on_it(): void
    {
        global $wpdb;

        $customer = (new CustomerFactory())->create();
        $first = (new ProductFactory())->create();
        $second = (new ProductFactory())->create();
        $third = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        foreach ([$first, $second, $third] as $product) {
            (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);
        }

        ProductReviewService::resetRuntimeCache();

        $this->assertNotNull(ProductReviewService::resolveOrderGrant($first->ID, $order->uuid));

        // Everything the remaining products need is in the memo now: no
        // further order or line query, whatever the order's size.
        $before = (int) $wpdb->num_queries;
        $this->assertNotNull(ProductReviewService::resolveOrderGrant($second->ID, $order->uuid));
        $this->assertNotNull(ProductReviewService::resolveOrderGrant($third->ID, $order->uuid));
        $this->assertSame($before, (int) $wpdb->num_queries, 'A second and third product must be answered from memory');
    }

    /**
     * Spec: Cases/reviews.md [1d]
     *   "The per-product reviews toggle is loaded once for a set of products and answered from memory after that."
     */
    public function test_reviews_enabled_state_is_primed_in_one_query(): void
    {
        global $wpdb;

        $on = (new ProductFactory())->create();
        $off = (new ProductFactory())->withDetailAttributes(['other_info' => ['reviews_enabled' => 'no']])->create();

        ProductReviewService::resetRuntimeCache();

        $before = (int) $wpdb->num_queries;
        ProductReviewService::primeReviewsEnabled([$on->ID, $off->ID]);
        $this->assertSame(1, (int) $wpdb->num_queries - $before, 'One detail query for the whole set');

        $before = (int) $wpdb->num_queries;
        $this->assertTrue(ProductReviewService::isReviewEnabledForProduct($on->ID));
        $this->assertFalse(ProductReviewService::isReviewEnabledForProduct($off->ID));
        $this->assertSame($before, (int) $wpdb->num_queries, 'Primed products are answered from memory');
    }

    /**
     * Spec: Cases/reviews.md [1e]
     *   "The order-review page asks the reviews table the same number of times whatever the order's size, signed in as the buyer or not."
     */
    public function test_the_page_asks_the_reviews_table_a_fixed_number_of_times(): void
    {
        $reviewQueries = 0;
        $counter = static function ($sql) use (&$reviewQueries) {
            if (stripos((string) $sql, 'fct_product_reviews') !== false) {
                $reviewQueries++;
            }
            return $sql;
        };
        add_filter('query', $counter);

        $count = function ($order) use (&$reviewQueries) {
            ProductReviewService::resetRuntimeCache();
            $reviewQueries = 0;
            ob_start();
            (new OrderReviewRenderer($order->uuid))->render();
            ob_end_clean();
            return $reviewQueries;
        };

        // A buyer with an account of their own, so the second half of the
        // case really is the buyer signed in.
        $buyerUserId = WPUserFactory::create(['user_email' => 'counted-buyer@example.com']);
        $customer = (new CustomerFactory())->create(['email' => 'counted-buyer@example.com']);
        $customer->user_id = $buyerUserId;
        $customer->save();
        $small = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($small->id, ['post_id' => (new ProductFactory())->create()->ID]);

        $large = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        for ($i = 0; $i < 5; $i++) {
            (new OrderItemFactory())->create($large->id, ['post_id' => (new ProductFactory())->create()->ID]);
        }

        try {
            // A guest holding the link.
            wp_set_current_user(0);
            $this->assertSame($count($small), $count($large), 'Five pending rows cost no more review queries than one (guest)');

            // The buyer signed in: every row's block would otherwise look
            // for the buyer's existing review for its CTA and its form.
            wp_set_current_user($buyerUserId);
            $this->assertSame($buyerUserId, get_current_user_id());
            $this->assertSame($count($small), $count($large), 'Five pending rows cost no more review queries than one (buyer)');
        } finally {
            wp_set_current_user(0);
            remove_filter('query', $counter);
        }
    }

    /**
     * Spec: Cases/reviews.md [16c]
     *   "The review slot lock is the buyer's email whenever the grant has one; only a customerless grant locks on the order."
     */
    public function test_the_slot_lock_follows_the_email_unless_the_grant_has_none(): void
    {
        $product = (new ProductFactory())->create();

        // A guest buyer whose customer row exists: two orders by the same
        // address for one product must contend on that address.
        // The factory always attaches a WP user; a guest checkout has none.
        $guest = (new CustomerFactory())->create(['email' => 'guest-buyer@example.com']);
        $guest->user_id = null;
        $guest->save();
        $orderA = (new OrderFactory())->create(['customer_id' => $guest->id, 'status' => Status::ORDER_COMPLETED]);
        $orderB = (new OrderFactory())->create(['customer_id' => $guest->id, 'status' => Status::ORDER_COMPLETED]);
        foreach ([$orderA, $orderB] as $order) {
            (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);
        }

        wp_set_current_user(0);
        $identityA = ProductReviewService::effectiveReviewerIdentity($orderA);
        $identityB = ProductReviewService::effectiveReviewerIdentity($orderB);

        $this->assertSame('guest-buyer@example.com', ProductReviewService::slotLockEmail($identityA));
        $this->assertSame(
            ProductReviewService::slotLockEmail($identityA),
            ProductReviewService::slotLockEmail($identityB),
            'Two orders by one address contend on one lock'
        );

        // The customer row gone: nothing stable but the order, so the order.
        $gone = (new CustomerFactory())->create();
        $orphan = (new OrderFactory())->create(['customer_id' => $gone->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($orphan->id, ['post_id' => $product->ID]);
        $orphan->customer_id = null;
        $orphan->save();
        $gone->delete();
        ProductReviewService::resetRuntimeCache();
        $orphan = Order::query()->find($orphan->id);

        $identity = ProductReviewService::effectiveReviewerIdentity($orphan, 'typed@example.com');
        $this->assertTrue($identity['order_keyed']);
        $this->assertSame('order-' . $orphan->id . '@review-slot.invalid', ProductReviewService::slotLockEmail($identity));
    }

    /**
     * Spec: Cases/reviews.md [16d]
     *   "A guest buyer whose address changed since their first review still gets one review per product through a later order link."
     */
    public function test_a_guest_buyer_locks_on_the_customer_row_not_the_address(): void
    {
        $product = (new ProductFactory())->create();

        $guest = (new CustomerFactory())->create(['email' => 'lock-then@example.com']);
        $guest->user_id = null;
        $guest->save();
        $order = (new OrderFactory())->create(['customer_id' => $guest->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        wp_set_current_user(0);
        $identity = ProductReviewService::effectiveReviewerIdentity($order);
        $this->assertSame((int) $guest->id, (int) $identity['customer_id'], 'The identity names the customer row');

        $lockThen = ProductReviewService::claimReviewSlot(
            $product->ID,
            $identity['user_id'],
            ProductReviewService::slotLockEmail($identity),
            0,
            $identity['customer_id']
        );
        $this->assertNotFalse($lockThen);
        $this->assertStringContainsString('_c' . $guest->id, $lockThen['name'], 'A guest buyer locks on their customer row');
        ProductReviewService::releaseReviewSlot($lockThen);

        // The address changes; the same customer still claims the same lock.
        $guest->email = 'lock-now@example.com';
        $guest->save();
        ProductReviewService::resetRuntimeCache();
        $order = Order::query()->find($order->id);
        $identityNow = ProductReviewService::effectiveReviewerIdentity($order);

        $lockNow = ProductReviewService::claimReviewSlot(
            $product->ID,
            $identityNow['user_id'],
            ProductReviewService::slotLockEmail($identityNow),
            0,
            $identityNow['customer_id']
        );
        $this->assertNotFalse($lockNow);
        $this->assertSame($lockThen['name'], $lockNow['name'], 'A changed address does not open a second lock');
        ProductReviewService::releaseReviewSlot($lockNow);

        // A guest typing that customer's current address, with no grant,
        // contends on the same lock too.
        $typed = ProductReviewService::effectiveReviewerIdentity(null, 'lock-now@example.com');
        $lockTyped = ProductReviewService::claimReviewSlot($product->ID, 0, 'lock-now@example.com', 0, $typed['customer_id']);
        $this->assertNotFalse($lockTyped);
        $this->assertSame($lockThen['name'], $lockTyped['name'], 'The typed address resolves to the customer row');
        ProductReviewService::releaseReviewSlot($lockTyped);
    }

    /**
     * Spec: Cases/reviews.md [1c]
     *   "A caller that already holds the order can seed the grant memo, and no grant query runs at all."
     */
    public function test_a_primed_order_answers_grants_without_a_query(): void
    {
        global $wpdb;

        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        ProductReviewService::resetRuntimeCache();
        $loaded = Order::query()->with('order_items')->find($order->id);
        ProductReviewService::primeOrderGrant($loaded);

        $before = (int) $wpdb->num_queries;
        $this->assertNotNull(ProductReviewService::resolveOrderGrant($product->ID, $order->uuid));
        $this->assertSame($before, (int) $wpdb->num_queries, 'A primed order needs no lookup');
    }

    // ─── Per-item grants ─────────────────────────────────────────────────

    /**
     * Spec: Cases/reviews.md [31]
     *   "An order hash unlocks an item that order contains, and not one it does not."
     */
    public function test_resolve_order_grant_is_scoped_to_the_item_when_one_is_given(): void
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'Red'],
                ['variation_title' => 'Blue'],
            ]);
        $red = $product->variants->firstWhere('variation_title', 'Red');
        $blue = $product->variants->firstWhere('variation_title', 'Blue');

        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID, 'object_id' => $red->id]);

        $this->assertNotNull(ProductReviewService::resolveOrderGrant($product->ID, $order->uuid, $red->id), 'Red is on the order');
        $this->assertNull(ProductReviewService::resolveOrderGrant($product->ID, $order->uuid, $blue->id), 'Blue is not');
        // Item 0 is the product-level question, answered exactly as before:
        // the order holds the product, whichever item.
        $this->assertNotNull(ProductReviewService::resolveOrderGrant($product->ID, $order->uuid), 'The product-level grant is unchanged');
        $this->assertNotNull(ProductReviewService::resolveOrderGrant($product->ID, $order->uuid, 0));
    }

    /**
     * Spec: Cases/reviews.md [32]
     *   "The item the review is filed under must belong to the product; a simple product files at product level."
     */
    public function test_resolve_review_item_checks_the_product_and_collapses_simple_products(): void
    {
        $variable = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['variation_title' => 'Red']]);
        $red = $variable->variants->first();

        $simple = (new ProductFactory())->create();
        $default = $simple->variants->first();

        $this->assertSame(
            (int) $red->id,
            ProductReviewService::resolveReviewItem($variable->ID, $red->id),
            'A variation of the product resolves to itself'
        );
        $this->assertNull(
            ProductReviewService::resolveReviewItem($simple->ID, $red->id),
            'A variation of another product is not an item of this one'
        );
        $this->assertSame(
            0,
            ProductReviewService::resolveReviewItem($simple->ID, $default->id),
            'A simple product has no items: its default variation is the product'
        );
        $this->assertSame(
            0,
            ProductReviewService::resolveReviewItem($variable->ID, 0),
            'No item asked for, none resolved'
        );
    }
}
