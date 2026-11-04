<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Spec: Cases/checkout-functional.md → "Placing an order — stock" section, items 27-35 and 63.
 *
 * TC-104. Proves what one real `place-order` request does to the shelf: the reservation
 * on success, its survival through a failed payment and a retry, and its release on
 * cancellation. Every counter assertion reads `fct_product_variations` /
 * `fct_product_details` back from the database as an exact integer or status string.
 *
 * The plugin boots this suite with stock management OFF, so each test that needs the
 * module arms its listeners through the actor; `_after` always disarms so the next Cest
 * starts from the suite default.
 */
class CheckoutStockCest
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
        $I->disarmStockListeners();
    }

    /**
     * Spec: Cases/checkout-functional.md [27]
     *   "Placing an order for a stock-managed variation moves exactly the ordered units from
     *    available onto hold, leaves committed and total stock unchanged, and records the
     *    reservation on the order so it can be undone later."
     */
    public function placingAnOrderMovesOrderedUnitsOntoHoldAndRecordsTheReservation(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(1500, 5);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(200, $status, 'place-order must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must report success before stock is inspected');

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT total_stock, available, on_hold, committed, stock_status FROM {$wpdb->prefix}fct_product_variations WHERE id = %d",
            $variation->id
        ), ARRAY_A);
        Assert::assertSame(3, (int) $row['available'], 'available must drop from 5 to 3 — exactly the two ordered units');
        Assert::assertSame(2, (int) $row['on_hold'], 'on_hold must rise from 0 to 2 — the two ordered units are reserved, not sold');
        Assert::assertSame(0, (int) $row['committed'], 'committed must stay 0 — nothing is committed until payment or delivery');
        Assert::assertSame(5, (int) $row['total_stock'], 'total_stock must stay 5 — a reservation does not change the shelf total');
        Assert::assertSame('in-stock', $row['stock_status'], 'three remaining units must keep the variation in stock');

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        $orderItemId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d AND object_id = %d",
            $order->id,
            $variation->id
        ));
        Assert::assertGreaterThan(0, $orderItemId, 'the order must carry an item line for the reserved variation');

        $movement = OrderMeta::query()->where('order_id', $order->id)->where('meta_key', 'stock_movement')->value('meta_value');
        Assert::assertIsArray($movement, 'the order must record a stock_movement entry so the reservation can be undone later');
        Assert::assertCount(1, $movement, 'one reserved line must produce exactly one stock_movement entry');
        Assert::assertArrayHasKey($orderItemId, $movement, 'the stock_movement entry must be keyed by the order item id. Movement: ' . wp_json_encode($movement));
        Assert::assertSame(2, (int) ($movement[$orderItemId]['on_hold'] ?? -1), 'the recorded reservation must equal the ordered quantity');
    }

    /**
     * Spec: Cases/checkout-functional.md [28]
     *   "Placing an order that takes the last available unit marks both the variation and its
     *    product as out of stock."
     */
    public function takingTheLastUnitMarksVariationAndProductOutOfStock(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(2000, 3);
        $cart = $this->buildInstantCart($I, [[$variation, 3]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(200, $status, 'ordering exactly the remaining stock must still be accepted. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must report success before stock is inspected');

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(0, (int) $stored->available, 'available must drop to 0 when the last units are taken');
        Assert::assertSame(3, (int) $stored->on_hold, 'all three units must be on hold');
        Assert::assertSame('out-of-stock', (string) $stored->stock_status, 'a variation with nothing left must be flagged out of stock');

        $detail = ProductDetail::query()->where('post_id', $variation->post_id)->first();
        Assert::assertNotNull($detail, 'the seeded product must have a detail row');
        Assert::assertSame('out-of-stock', (string) $detail->stock_availability, 'a product whose only variation ran out must be shown as out of stock');
    }

    /**
     * Spec: Cases/checkout-functional.md [29]
     *   "In a cart that mixes a stock-managed variation with one that is not stock-managed,
     *    only the stock-managed variation is reserved."
     */
    public function onlyTheStockManagedLineOfAMixedCartIsReserved(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $managed = $this->buildStockedVariation(1200, 8);
        $unmanaged = (new ProductFactory())->withVariantAttributes([
            'item_price'  => 700,
            'total_stock' => 4,
            'available'   => 4,
        ])->create()->variants->first();
        Assert::assertSame(0, (int) $unmanaged->manage_stock, 'precondition: the second variation must not be stock-managed');
        $cart = $this->buildInstantCart($I, [[$managed, 2], [$unmanaged, 3]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(200, $status, 'a mixed cart must be accepted. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must report success before stock is inspected');

        $storedManaged = ProductVariation::query()->find($managed->id);
        Assert::assertSame(6, (int) $storedManaged->available, 'the stock-managed line must lose exactly its two ordered units');
        Assert::assertSame(2, (int) $storedManaged->on_hold, 'the stock-managed line must hold exactly its two ordered units');

        $storedUnmanaged = ProductVariation::query()->find($unmanaged->id);
        Assert::assertSame(4, (int) $storedUnmanaged->available, 'a line that is not stock-managed must keep its available count');
        Assert::assertSame(0, (int) $storedUnmanaged->on_hold, 'a line that is not stock-managed must never be put on hold');
        Assert::assertSame(0, (int) $storedUnmanaged->committed, 'a line that is not stock-managed must never be committed');

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        $movement = OrderMeta::query()->where('order_id', $order->id)->where('meta_key', 'stock_movement')->value('meta_value');
        Assert::assertIsArray($movement, 'the order must record the reservation of the stock-managed line');
        Assert::assertCount(1, $movement, 'only the stock-managed line may appear in stock_movement. Movement: ' . wp_json_encode($movement));
    }

    /**
     * Spec: Cases/checkout-functional.md [30]
     *   "Placing an order for a stock-managed variation that has already run out is refused as
     *    out of stock and creates no order."
     */
    public function orderingAVariationThatHasRunOutIsRefused(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(900, 0, ['stock_status' => 'out-of-stock'], ['stock_availability' => 'out-of-stock']);
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(422, $status, 'an out-of-stock line must be refused with HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('is out of stock', (string) ($body['message'] ?? ''), 'the refusal must tell the shopper the item is out of stock');
        Assert::assertSame(0, Order::query()->count(), 'an out-of-stock refusal must not create an order');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'an out-of-stock refusal must not record a transaction');

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(0, (int) $stored->available, 'a refused order must not touch available');
        Assert::assertSame(0, (int) $stored->on_hold, 'a refused order must not reserve anything');
    }

    /**
     * Spec: Cases/checkout-functional.md [31]
     *   "When the payment step fails after the order is created, the order and its reservation
     *    are kept for a retry: the units stay on hold, the cart stays linked, and only the
     *    payment transaction is marked failed."
     */
    public function failedPaymentKeepsTheOrderAndItsReservationForRetry(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $this->declineStubPayments('Card declined by wp-browser stub');
        $variation = $this->buildStockedVariation(2500, 6);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(422, $status, 'a gateway failure must be answered with HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'a gateway failure must report failed');
        Assert::assertSame('Card declined by wp-browser stub', (string) ($body['message'] ?? ''), 'the gateway error message must reach the shopper');

        Assert::assertSame(1, Order::query()->count(), 'the order created before the gateway ran must be kept');
        $order = Order::query()->first();
        Assert::assertSame('on-hold', (string) $order->status, 'the kept order must remain on hold');
        Assert::assertSame('pending', (string) $order->payment_status, 'the kept order must still be payable — payment pending, not failed');

        $transactions = OrderTransaction::query()->where('order_id', $order->id)->get();
        Assert::assertCount(1, $transactions, 'one submission must record exactly one transaction');
        Assert::assertSame('failed', (string) $transactions->first()->status, 'the charge transaction must be marked failed so the retry gets a fresh attempt');

        $storedCart = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame((int) $order->id, (int) $storedCart->order_id, 'the cart must stay linked to the kept order');
        Assert::assertNotSame('completed', (string) $storedCart->stage, 'a failed payment must not complete the cart');

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(4, (int) $stored->available, 'the two ordered units must stay off the shelf while the order awaits a retry');
        Assert::assertSame(2, (int) $stored->on_hold, 'the two ordered units must stay on hold while the order awaits a retry');
        Assert::assertSame(0, (int) $stored->committed, 'a failed payment must not commit anything');
    }

    /**
     * Spec: Cases/checkout-functional.md [32]
     *   "Resubmitting the same cart after a failed payment reuses the pending order and does
     *    not reserve the units a second time."
     */
    public function resubmittingAfterFailedPaymentDoesNotReserveTwice(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(1800, 5);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);
        $payload = $this->checkoutPayload($cart->cart_hash);

        $this->declineStubPayments();
        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(422, $firstStatus, 'precondition: the first submission must fail at the gateway. Body: ' . wp_json_encode($firstBody));
        Assert::assertSame(1, Order::query()->count(), 'precondition: the first submission must leave one pending order');
        $firstOrderId = (int) Order::query()->first()->id;

        $this->acceptStubPayments();
        [$secondStatus, $secondBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $secondStatus, 'the retry must succeed. Body: ' . wp_json_encode($secondBody));
        Assert::assertSame('success', (string) ($secondBody['status'] ?? ''), 'the retry must report success');
        Assert::assertSame(1, Order::query()->count(), 'the retry must reuse the pending order, not create a second one');
        Assert::assertSame($firstOrderId, (int) Order::query()->first()->id, 'the surviving order must be the one the first submission created');

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(3, (int) $stored->available, 'available must still be 5 − 2 = 3 after the retry — reserved once, not twice');
        Assert::assertSame(2, (int) $stored->on_hold, 'on_hold must still be 2 after the retry — reserved once, not twice');

        $movement = OrderMeta::query()->where('order_id', $firstOrderId)->where('meta_key', 'stock_movement')->value('meta_value');
        Assert::assertIsArray($movement, 'the reused order must keep its stock_movement record');
        Assert::assertCount(1, $movement, 'the retry must not add a second stock_movement entry. Movement: ' . wp_json_encode($movement));
        Assert::assertSame(2, (int) (reset($movement)['on_hold'] ?? -1), 'the recorded reservation must still equal the ordered quantity after the retry');
    }

    /**
     * Spec: Cases/checkout-functional.md [33]
     *   "Resubmitting the same cart after a failed payment is not refused as out of stock when
     *    the shelf now holds fewer units than the cart asks for, because the order's own
     *    reservation is counted."
     */
    public function resubmittingAfterFailedPaymentIsNotRefusedWhenOwnReservationEmptiedTheShelf(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(1100, 2);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);
        $payload = $this->checkoutPayload($cart->cart_hash);

        $this->declineStubPayments();
        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(422, $firstStatus, 'precondition: the first submission must fail at the gateway. Body: ' . wp_json_encode($firstBody));
        $afterFirst = ProductVariation::query()->find($variation->id);
        Assert::assertSame(0, (int) $afterFirst->available, 'precondition: the first submission must have taken the last two units');
        Assert::assertSame('out-of-stock', (string) $afterFirst->stock_status, 'precondition: the variation must read out of stock on the shelf');

        $this->acceptStubPayments();
        [$secondStatus, $secondBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $secondStatus, 'the retry must not be refused as out of stock — the shopper already holds those units. Body: ' . wp_json_encode($secondBody));
        Assert::assertSame('success', (string) ($secondBody['status'] ?? ''), 'the retry must report success');
        Assert::assertSame(1, Order::query()->count(), 'the retry must reuse the pending order');

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(0, (int) $stored->available, 'available must stay 0 — the retry must not reserve again');
        Assert::assertSame(2, (int) $stored->on_hold, 'on_hold must stay 2 — the retry must not reserve again');
    }

    /**
     * Spec: Cases/checkout-functional.md [34]
     *   "Cancelling the pending order returns the held units to available and the product is in
     *    stock again."
     */
    public function cancellingThePendingOrderReturnsHeldUnitsToTheShelf(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(1300, 2);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'precondition: place-order must succeed. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Order::query()->count(), 'precondition: one order must exist');
        $order = Order::query()->first();
        $afterPlacement = ProductVariation::query()->find($variation->id);
        Assert::assertSame(0, (int) $afterPlacement->available, 'precondition: the order must have taken the last two units');
        Assert::assertSame('out-of-stock', (string) ProductDetail::query()->where('post_id', $variation->post_id)->value('stock_availability'), 'precondition: the product must read out of stock after placement');

        $I->loginAsSuperAdmin();
        [$cancelStatus, $cancelBody] = $I->put('/fluent-cart/v2/orders/' . $order->id . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);
        Assert::assertSame(200, $cancelStatus, 'cancelling the pending order must succeed. Body: ' . wp_json_encode($cancelBody));
        Assert::assertSame('canceled', (string) Order::query()->find($order->id)->status, 'the order must persist as canceled');

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(2, (int) $stored->available, 'both held units must return to available on cancellation');
        Assert::assertSame(0, (int) $stored->on_hold, 'the hold must be cleared on cancellation');
        Assert::assertSame(0, (int) $stored->committed, 'a cancelled pending order must commit nothing');
        Assert::assertSame('in-stock', (string) $stored->stock_status, 'the variation must read in stock again');
        Assert::assertSame('in-stock', (string) ProductDetail::query()->where('post_id', $variation->post_id)->value('stock_availability'), 'the product must be shown as in stock again');

        $movement = OrderMeta::query()->where('order_id', $order->id)->where('meta_key', 'stock_movement')->value('meta_value');
        Assert::assertIsArray($movement, 'the stock_movement record must survive the cancellation');
        Assert::assertSame(0, (int) (reset($movement)['on_hold'] ?? -1), 'the recorded reservation must be written down to 0 once released');
    }

    /**
     * Spec: Cases/checkout-functional.md [35]
     *   "With stock management switched off, placing an order for a variation flagged for stock
     *    management changes no stock counter and is never refused for stock."
     */
    public function withStockManagementOffPlacingAnOrderTouchesNoCounterAndIsNeverRefused(FunctionalTester $I): void
    {
        $I->disarmStockListeners();
        $variation = $this->buildStockedVariation(1000, 1);
        $cart = $this->buildInstantCart($I, [[$variation, 3]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(200, $status, 'with the module off, ordering more than the shelf holds must not be refused. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'with the module off, place-order must report success');
        Assert::assertSame(1, Order::query()->count(), 'with the module off, the order must be created');

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(1, (int) $stored->available, 'with the module off, available must not change');
        Assert::assertSame(0, (int) $stored->on_hold, 'with the module off, nothing may be put on hold');
        Assert::assertSame(0, (int) $stored->committed, 'with the module off, nothing may be committed');
        Assert::assertSame('in-stock', (string) $stored->stock_status, 'with the module off, the stock flag must not change');
        Assert::assertSame(0, OrderMeta::query()->where('order_id', Order::query()->first()->id)->where('meta_key', 'stock_movement')->count(), 'with the module off, no stock_movement record may be written');
    }

    /**
     * Spec: Cases/checkout-functional.md [63]
     *   "A second order for the same variation adds its units to the units already on hold
     *    instead of replacing them, and cancelling one order releases only that order's units."
     */
    public function secondOrderAddsToUnitsAlreadyOnHoldAndCancelReleasesOnlyItsOwn(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(1400, 10);

        $firstCart = $this->buildInstantCart($I, [[$variation, 2]]);
        [, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($firstCart->cart_hash, ['billing_email' => 'first-holder@example.test']));
        Assert::assertSame('success', (string) ($firstBody['status'] ?? ''), 'precondition: the first order must be placed. Body: ' . wp_json_encode($firstBody));
        $afterFirst = ProductVariation::query()->find($variation->id);
        Assert::assertSame(2, (int) $afterFirst->on_hold, 'precondition: the first order must hold two units');

        $secondCart = $this->buildInstantCart($I, [[$variation, 3]]);
        [, $secondBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($secondCart->cart_hash, ['billing_email' => 'second-holder@example.test']));
        Assert::assertSame('success', (string) ($secondBody['status'] ?? ''), 'the second order must be placed. Body: ' . wp_json_encode($secondBody));
        Assert::assertSame(2, Order::query()->count(), 'two carts must produce two orders');

        $afterSecond = ProductVariation::query()->find($variation->id);
        Assert::assertSame(5, (int) $afterSecond->on_hold, 'on_hold must be 2 + 3 = 5 — the second order adds to the units already held, it does not replace them');
        Assert::assertSame(5, (int) $afterSecond->available, 'available must be 10 − 2 − 3 = 5');

        $firstOrder = Order::query()->orderBy('id', 'asc')->first();
        $secondOrder = Order::query()->orderBy('id', 'desc')->first();
        $I->loginAsSuperAdmin();
        [$cancelStatus, $cancelBody] = $I->put('/fluent-cart/v2/orders/' . $firstOrder->id . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);
        Assert::assertSame(200, $cancelStatus, 'cancelling the first order must succeed. Body: ' . wp_json_encode($cancelBody));

        $afterCancel = ProductVariation::query()->find($variation->id);
        Assert::assertSame(3, (int) $afterCancel->on_hold, 'cancelling the first order must release only its two units — the second order\'s three stay on hold');
        Assert::assertSame(7, (int) $afterCancel->available, 'available must be 5 + 2 = 7 after the first order\'s units return');
        $secondMovement = OrderMeta::query()->where('order_id', $secondOrder->id)->where('meta_key', 'stock_movement')->value('meta_value');
        Assert::assertSame(3, (int) (reset($secondMovement)['on_hold'] ?? -1), 'the second order\'s recorded reservation must survive the first order\'s cancellation');
        $firstMovement = OrderMeta::query()->where('order_id', $firstOrder->id)->where('meta_key', 'stock_movement')->value('meta_value');
        Assert::assertSame(0, (int) (reset($firstMovement)['on_hold'] ?? -1), 'the cancelled order\'s recorded reservation must be written down to 0');
    }

    /**
     * Spec: Cases/checkout-functional.md [73]
     *   "Retrying after a failed payment with a larger quantity reserves the extra units, so
     *    the order holds exactly what it now asks for."
     */
    public function retryWithLargerQuantityReservesTheExtraUnits(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(1000, 10);
        $cart = $this->failFirstAttempt($I, [[$variation, 1]]);

        $this->setCartLines($cart, [[$variation, 9]]);
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the retry for 9 must be accepted — 9 free units plus the 1 already held. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Order::query()->count(), 'the retry must reuse the pending order');

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(9, (int) $stored->on_hold, 'the order now asks for 9, so 9 units must be on hold, not the 1 from the first attempt');
        Assert::assertSame(1, (int) $stored->available, 'only 10 − 9 = 1 unit may stay available to other shoppers');
        Assert::assertSame(9, $this->recordedHold(), 'the recorded reservation must follow the new quantity');
    }

    /**
     * Spec: Cases/checkout-functional.md [74]
     *   "Retrying after a failed payment with more units than the shelf plus the order's own
     *    reservation is refused as out of stock and reserves nothing more."
     */
    public function retryAskingForMoreThanShelfPlusOwnReservationIsRefused(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(1000, 10);
        $cart = $this->failFirstAttempt($I, [[$variation, 1]]);

        $this->setCartLines($cart, [[$variation, 11]]);
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame(422, $status, 'a retry for 11 of 10 units must be refused with HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('is out of stock', (string) ($body['message'] ?? ''), 'the refusal must be the stock error');

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(1, (int) $stored->on_hold, 'the refused retry must leave the first reservation as it was');
        Assert::assertSame(9, (int) $stored->available, 'the refused retry must not take more units off the shelf');
    }

    /**
     * Spec: Cases/checkout-functional.md [75]
     *   "Retrying after a failed payment with a smaller quantity returns the units no longer
     *    asked for to the shelf."
     */
    public function retryWithSmallerQuantityReleasesTheDifference(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(1000, 10);
        $cart = $this->failFirstAttempt($I, [[$variation, 4]]);

        $this->setCartLines($cart, [[$variation, 2]]);
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the smaller retry must be accepted. Body: ' . wp_json_encode($body));

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(2, (int) $stored->on_hold, 'on_hold must drop from 4 to the 2 now ordered');
        Assert::assertSame(8, (int) $stored->available, 'the 2 units no longer ordered must return to the shelf (6 + 2)');
        Assert::assertSame(2, $this->recordedHold(), 'the recorded reservation must follow the new quantity');
    }

    /**
     * Spec: Cases/checkout-functional.md [76]
     *   "Retrying after a failed payment with a different variation on the reused order line
     *    releases the first variation's units and reserves the new variation's units."
     */
    public function retryWithAnotherVariationMovesTheReservation(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $first = $this->buildStockedVariation(1000, 10);
        $second = $this->buildStockedVariation(1000, 10);
        $cart = $this->failFirstAttempt($I, [[$first, 3]]);

        $this->setCartLines($cart, [[$second, 2]]);
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the retry with another variation must be accepted. Body: ' . wp_json_encode($body));

        $old = ProductVariation::query()->find($first->id);
        Assert::assertSame(0, (int) $old->on_hold, 'the first variation is no longer ordered, so its hold must be released');
        Assert::assertSame(10, (int) $old->available, 'the first variation must be back to 10 available');
        $new = ProductVariation::query()->find($second->id);
        Assert::assertSame(2, (int) $new->on_hold, 'the new variation must hold the 2 units now ordered');
        Assert::assertSame(8, (int) $new->available, 'the new variation must have 10 − 2 available');
        Assert::assertSame(2, $this->recordedHold(), 'the recorded reservation must be the new variation\'s 2 units');
    }

    /**
     * Spec: Cases/checkout-functional.md [77]
     *   "Cancelling an order whose quantity was raised on a retry returns exactly the units it
     *    holds, so the shelf ends where it started."
     */
    public function cancellingARaisedRetryRestoresTheShelfExactly(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(1000, 100);
        $cart = $this->failFirstAttempt($I, [[$variation, 1]]);

        $this->setCartLines($cart, [[$variation, 4]]);
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'precondition: the retry must be accepted. Body: ' . wp_json_encode($body));

        $order = Order::query()->first();
        $I->loginAsSuperAdmin();
        [$cancelStatus, $cancelBody] = $I->put('/fluent-cart/v2/orders/' . $order->id . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);
        Assert::assertSame(200, $cancelStatus, 'cancelling must succeed. Body: ' . wp_json_encode($cancelBody));

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(100, (int) $stored->available, 'available must return to 100, not 103');
        Assert::assertSame(0, (int) $stored->on_hold, 'nothing may stay on hold after the cancellation');
    }

    /**
     * Spec: Cases/checkout-functional.md [78]
     *   "Refunding with restock an order whose quantity was raised on a retry returns exactly
     *    the units it holds, so the shelf ends where it started."
     */
    public function refundingARaisedRetryRestoresTheShelfExactly(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = $this->buildStockedVariation(1000, 100);
        $cart = $this->failFirstAttempt($I, [[$variation, 1]]);

        $this->setCartLines($cart, [[$variation, 4]]);
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'precondition: the retry must be accepted. Body: ' . wp_json_encode($body));

        $order = Order::query()->first();
        $order->payment_status = Status::PAYMENT_PAID;
        $order->total_paid = $order->total_amount;
        $order->save();
        $transaction = OrderTransaction::query()->where('order_id', $order->id)->orderBy('id', 'desc')->first();
        $transaction->status = Status::TRANSACTION_SUCCEEDED;
        $transaction->total = $order->total_amount;
        $transaction->save();
        $itemId = (int) OrderItem::query()->where('order_id', $order->id)->value('id');

        $I->loginAsSuperAdmin();
        [$refundStatus, $refundBody] = $I->post('/fluent-cart/v2/orders/' . $order->id . '/refund', [
            'refund_info' => [
                'transaction_id' => $transaction->id,
                'amount'         => 40,
                'manageStock'    => 'true',
                'item_ids'       => [$itemId],
                'refunded_items' => [[
                    'id'               => $itemId,
                    'variation_id'     => (int) $variation->id,
                    'restore_quantity' => 4,
                ]],
            ],
        ]);
        Assert::assertSame(200, $refundStatus, 'the refund must succeed. Body: ' . wp_json_encode($refundBody));

        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(100, (int) $stored->available, 'available must return to 100, not 103');
        Assert::assertSame(0, (int) $stored->on_hold, 'nothing may stay on hold after the full restock');
    }

    /**
     * Spec: Cases/checkout-functional.md [79]
     *   "Retrying after a failed payment that held no stock with a stock-managed variation
     *    reserves that variation's units."
     */
    public function retryAddingAStockManagedLineToAnOrderThatHeldNothingReservesIt(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $managed = $this->buildStockedVariation(1000, 10);
        $cart = $this->failFirstAttempt($I, [[$this->buildUnmanagedVariation(), 1]]);

        $this->setCartLines($cart, [[$managed, 3]]);
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the retry must be accepted. Body: ' . wp_json_encode($body));

        $stored = ProductVariation::query()->find($managed->id);
        Assert::assertSame(3, (int) $stored->on_hold, 'the 3 units now ordered must be on hold');
        Assert::assertSame(7, (int) $stored->available, 'only 10 − 3 may stay available to other shoppers');
        Assert::assertSame(3, $this->recordedHold(), 'the order must record its new reservation');
    }

    /**
     * Spec: Cases/checkout-functional.md [80]
     *   "After a failed retry that switched to a variation without stock management, a later
     *    retry back to a stock-managed variation reserves its units again."
     */
    public function retryBackToAStockManagedLineAfterAnEmptyRetryReservesIt(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $managed = $this->buildStockedVariation(1000, 10);
        $cart = $this->failFirstAttempt($I, [[$managed, 2]]);

        $this->setCartLines($cart, [[$this->buildUnmanagedVariation(), 1]]);
        $this->declineStubPayments();
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame(422, $status, 'precondition: the middle retry must fail at the gateway. Body: ' . wp_json_encode($body));
        $this->acceptStubPayments();
        Assert::assertSame(0, (int) ProductVariation::query()->find($managed->id)->on_hold, 'precondition: the middle retry must release the first hold');

        $this->setCartLines($cart, [[$managed, 2]]);
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the last retry must be accepted. Body: ' . wp_json_encode($body));

        $stored = ProductVariation::query()->find($managed->id);
        Assert::assertSame(2, (int) $stored->on_hold, 'the 2 units now ordered must be on hold again');
        Assert::assertSame(8, (int) $stored->available, 'only 10 − 2 may stay available to other shoppers');
        Assert::assertSame(2, $this->recordedHold(), 'the order must record its new reservation');
    }

    private function buildUnmanagedVariation()
    {
        $variation = (new ProductFactory())->withVariantAttributes([
            'item_price'  => 700,
            'total_stock' => 4,
            'available'   => 4,
        ])->create()->variants->first();
        Assert::assertSame(0, (int) $variation->manage_stock, 'precondition: the variation must not be stock-managed');

        return $variation;
    }

    /**
     * Place the cart once with a declined payment and switch the stub back to accepting, so the
     * next submission is a retry of the same pending order.
     */
    private function failFirstAttempt(FunctionalTester $I, array $lines): Cart
    {
        $cart = $this->buildInstantCart($I, $lines);
        $this->declineStubPayments();
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame(422, $status, 'precondition: the first submission must fail at the gateway. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Order::query()->count(), 'precondition: the first submission must leave one pending order');
        $this->acceptStubPayments();

        return $cart;
    }

    /**
     * What the shopper does between attempts: change the cart lines.
     */
    private function setCartLines(Cart $cart, array $lines): void
    {
        $items = [];
        foreach ($lines as $line) {
            $items[] = CartHelper::generateCartItemFromVariation($line[0], (int) $line[1]);
        }
        $cart->cart_data = $items;
        $cart->save();
    }

    /**
     * The on_hold units the single order's stock_movement records, or -1 when it records nothing.
     */
    private function recordedHold(): int
    {
        $movement = OrderMeta::query()->where('order_id', Order::query()->first()->id)->where('meta_key', 'stock_movement')->value('meta_value');
        Assert::assertIsArray($movement, 'the order must keep a stock_movement record');
        Assert::assertCount(1, $movement, 'one order line must leave one stock_movement entry. Movement: ' . wp_json_encode($movement));

        return (int) (array_values($movement)[0]['on_hold'] ?? -1);
    }
}
