<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Spec: Cases/checkout-functional.md → "Placing an order — resubmission and idempotency" section, items 56-64.
 *
 * TC-107. Proves that submitting one cart more than once never produces more than one order
 * or charge: a retry after a failed payment, a duplicate while the charge is pending, a
 * resubmission with a changed quantity, carts tied to an already-paid order, and a
 * submission that races another submission of the same cart. Every count is read from the
 * `fct_orders` / `fct_order_transactions` rows.
 */
class PlaceOrderIdempotencyCest
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
    }

    /**
     * Spec: Cases/checkout-functional.md [56]
     *   "Resubmitting a cart after a failed payment retries the same charge in place: the
     *    transaction keeps its identifier, goes back to pending with its attempt counter raised,
     *    and no second transaction is recorded."
     */
    public function retryAfterFailedPaymentReusesTheChargeWithARaisedAttempt(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 2400])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash);

        $this->declineStubPayments();
        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(422, $firstStatus, 'precondition: the first submission must fail at the gateway. Body: ' . wp_json_encode($firstBody));
        $failed = OrderTransaction::query()->first();
        Assert::assertNotNull($failed, 'precondition: the first submission must record a transaction');
        Assert::assertSame('failed', (string) $failed->status, 'precondition: the first transaction must be marked failed');
        Assert::assertSame(0, (int) (($failed->meta ?: [])['payment_attempt'] ?? 0), 'precondition: a first attempt carries no attempt counter');

        $this->acceptStubPayments();
        [$secondStatus, $secondBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $secondStatus, 'the retry must succeed. Body: ' . wp_json_encode($secondBody));
        Assert::assertSame(1, Order::query()->count(), 'the retry must not create a second order');
        Assert::assertSame(1, OrderTransaction::query()->count(), 'the retry must not record a second transaction');
        $retried = OrderTransaction::query()->first();
        Assert::assertSame((string) $failed->uuid, (string) $retried->uuid, 'the retry must reuse the same transaction identifier');
        Assert::assertSame((int) $failed->id, (int) $retried->id, 'the retry must reuse the same transaction row');
        Assert::assertSame('pending', (string) $retried->status, 'the retried transaction must be pending again for the gateway');
        Assert::assertSame(1, (int) (($retried->meta ?: [])['payment_attempt'] ?? 0), 'a retry after a failure must raise the attempt counter so the gateway gets a fresh idempotency key');
        Assert::assertSame(2400, (int) $retried->total, 'the retried charge must still be for the full order total');
    }

    /**
     * Spec: Cases/checkout-functional.md [57]
     *   "Resubmitting a cart whose charge is still pending reuses that transaction unchanged,
     *    with the attempt counter not raised, so the gateway sees the same attempt rather than
     *    a new charge."
     */
    public function duplicateWhileTheChargeIsPendingReusesTheTransactionUnchanged(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1700])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash);
        $this->leaveStubPaymentsPending();

        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(200, $firstStatus, 'precondition: the first submission must be handed off. Body: ' . wp_json_encode($firstBody));
        $pending = OrderTransaction::query()->first();
        Assert::assertSame('pending', (string) $pending->status, 'precondition: the charge must still be pending after the hand-off');
        Assert::assertNotSame('completed', (string) Cart::query()->where('cart_hash', $cart->cart_hash)->value('stage'), 'precondition: a pending hand-off must not complete the cart');

        [$secondStatus, $secondBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $secondStatus, 'the duplicate must be accepted and handed off again. Body: ' . wp_json_encode($secondBody));
        Assert::assertSame(1, Order::query()->count(), 'the duplicate must not create a second order');
        Assert::assertSame(1, OrderTransaction::query()->count(), 'the duplicate must not record a second transaction');
        $reused = OrderTransaction::query()->first();
        Assert::assertSame((string) $pending->uuid, (string) $reused->uuid, 'the duplicate must reuse the pending transaction identifier');
        Assert::assertSame('pending', (string) $reused->status, 'the reused transaction must stay pending');
        Assert::assertSame(0, (int) (($reused->meta ?: [])['payment_attempt'] ?? 0), 'a duplicate of a pending charge must not raise the attempt counter — the gateway must see the same attempt');
    }

    /**
     * Spec: Cases/checkout-functional.md [58]
     *   "Resubmitting after the shopper changed the quantity updates the same pending order's
     *    line, totals, and charge amount instead of creating a second order."
     */
    public function resubmittingWithAChangedQuantityUpdatesTheSameOrderAndCharge(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash);

        $this->declineStubPayments();
        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(422, $firstStatus, 'precondition: the first submission must fail at the gateway. Body: ' . wp_json_encode($firstBody));
        $firstOrder = Order::query()->first();
        Assert::assertSame(1000, (int) $firstOrder->total_amount, 'precondition: the first order must total one unit');

        // The shopper goes back and raises the quantity; the cart row is what the cart page rewrites.
        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $stored->cart_data = [CartHelper::generateCartItemFromVariation($variation, 3)];
        $stored->save();
        Assert::assertSame(3, (int) Cart::query()->where('cart_hash', $cart->cart_hash)->first()->cart_data[0]['quantity'], 'precondition: the cart line must now hold three units');

        $this->acceptStubPayments();
        [$secondStatus, $secondBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $secondStatus, 'the resubmission must answer HTTP 200. Body: ' . wp_json_encode($secondBody));
        Assert::assertSame('success', (string) ($secondBody['status'] ?? ''), 'the resubmission must succeed. Body: ' . wp_json_encode($secondBody));
        Assert::assertSame(1, Order::query()->count(), 'a changed quantity must update the pending order, not create a second one');
        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare("SELECT id, subtotal, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $firstOrder->id), ARRAY_A);
        Assert::assertSame(3000, (int) $order['subtotal'], 'the order subtotal must follow the new quantity');
        Assert::assertSame(3000, (int) $order['total_amount'], 'the order total must follow the new quantity');
        $items = $wpdb->get_results($wpdb->prepare("SELECT quantity, line_total FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $firstOrder->id), ARRAY_A);
        Assert::assertCount(1, $items, 'the order must still have exactly one line');
        Assert::assertSame(3, (int) $items[0]['quantity'], 'the existing line must carry the new quantity');
        Assert::assertSame(3000, (int) $items[0]['line_total'], 'the existing line total must follow the new quantity');
        Assert::assertSame(1, OrderTransaction::query()->count(), 'the resubmission must not record a second transaction');
        Assert::assertSame(3000, (int) OrderTransaction::query()->first()->total, 'the pending charge must be raised to the new order total');
    }

    /**
     * Spec: Cases/checkout-functional.md [59]
     *   "A pay-for-order cart locked to an order that is already paid is refused as already
     *    completed and creates no order or transaction."
     */
    public function lockedCartOfAPaidOrderIsRefusedAsAlreadyCompleted(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'paid-link@example.test']);
        // Paid but still on hold — an admin status change keeps payment_status, so this state is
        // reachable in production; it isolates the payment-finality guard from the order-status one.
        $paid = (new OrderFactory())->withTransaction()->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => 5000,
            'total_amount'   => 5000,
            'total_paid'     => 5000,
        ]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]], [
            'order_id'      => $paid->id,
            'customer_id'   => $customer->id,
            'stage'         => 'intended',
            'checkout_data' => ['is_locked' => 'yes'],
        ]);
        $ordersBefore = Order::query()->count();
        $transactionsBefore = OrderTransaction::query()->count();

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['billing_email' => 'paid-link@example.test']));

        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'a locked cart of a paid order must be refused. Status ' . $status . ', body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('already completed this order', (string) ($body['message'] ?? ''), 'the refusal must say the order is already completed');
        Assert::assertSame($ordersBefore, Order::query()->count(), 'the refusal must not create an order');
        Assert::assertSame($transactionsBefore, OrderTransaction::query()->count(), 'the refusal must not record a transaction');
        Assert::assertSame((int) $paid->id, (int) Cart::query()->where('cart_hash', $cart->cart_hash)->value('order_id'), 'the locked cart must stay bound to its paid order');
    }

    /**
     * Spec: Cases/checkout-functional.md [60]
     *   "A cart still linked to a paid order but never marked completed is detached and gets a
     *    fresh order, leaving the paid order untouched."
     */
    public function staleCartOfAPaidOrderIsDetachedAndGetsAFreshOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'stale-link@example.test']);
        // Paid but still on hold — an admin status change keeps payment_status, so this state is
        // reachable in production; it isolates the payment-finality guard from the order-status one.
        $paid = (new OrderFactory())->withTransaction()->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => 3000,
            'total_amount'   => 3000,
            'total_paid'     => 3000,
        ]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 3000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]], [
            'order_id'    => $paid->id,
            'customer_id' => $customer->id,
            'stage'       => 'intended',
        ]);
        $paidTransactionCount = OrderTransaction::query()->where('order_id', $paid->id)->count();

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['billing_email' => 'stale-link@example.test']));

        Assert::assertSame(200, $status, 'a stale cart must be allowed to check out again. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the resurrected cart must place a fresh order');
        Assert::assertSame(2, Order::query()->count(), 'a fresh order must be created next to the paid one');
        $fresh = Order::query()->orderBy('id', 'desc')->first();
        Assert::assertNotSame((int) $paid->id, (int) $fresh->id, 'the fresh order must not be the paid one');
        Assert::assertSame((int) $fresh->id, (int) Cart::query()->where('cart_hash', $cart->cart_hash)->value('order_id'), 'the cart must be re-linked to the fresh order');
        Assert::assertSame('pending', (string) $fresh->payment_status, 'the fresh order must start with payment pending');
        $untouched = Order::query()->find($paid->id);
        Assert::assertSame('paid', (string) $untouched->payment_status, 'the paid order must keep its payment status');
        Assert::assertSame('on-hold', (string) $untouched->status, 'the paid order must keep its order status — it must not be rewritten as the resurrected cart\'s draft');
        Assert::assertSame(3000, (int) $untouched->total_paid, 'the paid order must keep its paid amount');
        Assert::assertSame($paidTransactionCount, OrderTransaction::query()->where('order_id', $paid->id)->count(), 'the paid order must not gain a transaction');
    }

    /**
     * Spec: Cases/checkout-functional.md [61]
     *   "A submission that arrives while another submission of the same cart is still being
     *    processed is refused as already being processed and creates nothing; once that other
     *    submission has finished, the cart can be submitted."
     *
     * The other submission is simulated by a second database session holding the cart's
     * checkout lock; production waits up to ten seconds for it, so this test takes that long.
     */
    public function submissionDuringAnotherSubmissionOfTheSameCartIsRefused(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1900])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash);
        $other = $this->holdCheckoutLock($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(429, $status, 'a submission racing another one must be refused with HTTP 429. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'the racing submission must report failed');
        Assert::assertStringContainsString('already being processed', (string) ($body['message'] ?? ''), 'the refusal must tell the shopper the order is already being processed');
        Assert::assertSame(0, Order::query()->count(), 'the refused submission must not create an order');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'the refused submission must not record a transaction');

        $this->releaseCheckoutLock($other, $cart->cart_hash);
        [$laterStatus, $laterBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $laterStatus, 'once the other submission has finished, the cart must check out. Body: ' . wp_json_encode($laterBody));
        Assert::assertSame(1, Order::query()->count(), 'the later submission must create exactly one order');
        Assert::assertSame(1, OrderTransaction::query()->count(), 'the later submission must record exactly one transaction');
    }

    /**
     * Spec: Cases/checkout-functional.md [62]
     *   "A submission takes the cart's lock before it re-reads the cart and looks for an earlier
     *    order, so two simultaneous first submissions can never both see \"no order yet\"."
     *
     * The serialization invariant behind [61], pinned deterministically: every SQL statement of
     * a resubmission is recorded through WordPress's `query` filter and the lock statement must
     * come before the fresh cart read and the previous-order read. Moving the lock after that
     * read is exactly the double-order race, and reorders this sequence.
     */
    public function submissionTakesTheCartLockBeforeLookingForAnEarlierOrder(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 2100])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash);
        $this->declineStubPayments();
        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(422, $firstStatus, 'precondition: the first submission must leave a pending order behind. Body: ' . wp_json_encode($firstBody));
        $orderId = (int) Order::query()->first()->id;

        $statements = [];
        $recorder = function ($sql) use (&$statements) {
            $statements[] = (string) $sql;
            return $sql;
        };
        add_filter('query', $recorder);
        $this->acceptStubPayments();
        [$secondStatus, $secondBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        remove_filter('query', $recorder);
        Assert::assertSame(200, $secondStatus, 'precondition: the resubmission must succeed. Body: ' . wp_json_encode($secondBody));

        global $wpdb;
        $lockAt = $cartReadAt = $orderReadAt = null;
        foreach ($statements as $index => $sql) {
            $normalized = strtolower(str_replace('`', '', $sql));
            if ($lockAt === null && strpos($normalized, 'get_lock(') !== false) {
                $lockAt = $index;
                continue;
            }
            if ($lockAt !== null && $cartReadAt === null && strpos($normalized, 'select') === 0 && strpos($normalized, $wpdb->prefix . 'fct_carts') !== false && strpos($normalized, $cart->cart_hash) !== false) {
                $cartReadAt = $index;
                continue;
            }
            if ($orderReadAt === null && strpos($normalized, 'select') === 0 && strpos($normalized, $wpdb->prefix . 'fct_orders') !== false && preg_match('/fct_orders\.id\s*=\s*\'?' . $orderId . '\'?/', $normalized)) {
                $orderReadAt = $index;
            }
        }
        Assert::assertNotNull($lockAt, 'the resubmission must take the cart lock. Statements: ' . wp_json_encode($statements));
        Assert::assertNotNull($orderReadAt, 'the resubmission must look up the earlier order. Statements: ' . wp_json_encode($statements));
        Assert::assertLessThan($orderReadAt, $lockAt, 'the cart lock must be taken BEFORE the earlier order is looked up — otherwise two first submissions can both see no order and create two');
        Assert::assertNotNull($cartReadAt, 'the cart must be re-read after the lock was taken. Statements: ' . wp_json_encode($statements));
        Assert::assertLessThan($orderReadAt, $cartReadAt, 'the fresh cart read under the lock must precede the earlier-order lookup');
    }

    /**
     * Spec: Cases/checkout-functional.md [64]
     *   "Every further retry after another failed payment raises the attempt counter again on the
     *    same transaction, and a later duplicate of a pending charge leaves the counter where it
     *    is."
     *
     * The counter feeds the gateway idempotency seed: a retry that reused a stale counter would
     * be answered with the cached error of the previous attempt instead of being tried again.
     */
    public function attemptCounterKeepsAdvancingAcrossRepeatedFailuresAndHoldsOnADuplicate(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 3300])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash);
        $this->declineStubPayments();

        $expectedAttempts = [0, 1, 2];
        $transactionId = null;
        foreach ($expectedAttempts as $round => $expectedAttempt) {
            [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
            Assert::assertSame(422, $status, "failure round {$round} must be refused by the gateway. Body: " . wp_json_encode($body));
            Assert::assertSame(1, OrderTransaction::query()->count(), "failure round {$round} must not add a transaction");
            $transaction = OrderTransaction::query()->first();
            $transactionId = $transactionId ?? (int) $transaction->id;
            Assert::assertSame($transactionId, (int) $transaction->id, "failure round {$round} must reuse the same transaction row");
            Assert::assertSame('failed', (string) $transaction->status, "failure round {$round} must leave the transaction failed");
            Assert::assertSame($expectedAttempt, (int) (($transaction->meta ?: [])['payment_attempt'] ?? 0), "after failure round {$round} the attempt counter must read {$expectedAttempt} — each retry after a failure advances it by exactly one");
        }

        $this->leaveStubPaymentsPending();
        [$pendingStatus, $pendingBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(200, $pendingStatus, 'the hand-off retry must be accepted. Body: ' . wp_json_encode($pendingBody));
        $pending = OrderTransaction::query()->first();
        Assert::assertSame($transactionId, (int) $pending->id, 'the hand-off retry must reuse the same transaction row');
        Assert::assertSame('pending', (string) $pending->status, 'the hand-off retry must leave the transaction pending');
        Assert::assertSame(3, (int) (($pending->meta ?: [])['payment_attempt'] ?? 0), 'a retry after the third failure must advance the counter to 3');

        [$duplicateStatus, $duplicateBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(200, $duplicateStatus, 'the duplicate of the pending charge must be accepted. Body: ' . wp_json_encode($duplicateBody));
        $duplicate = OrderTransaction::query()->first();
        Assert::assertSame(1, OrderTransaction::query()->count(), 'the duplicate must not add a transaction');
        Assert::assertSame(3, (int) (($duplicate->meta ?: [])['payment_attempt'] ?? 0), 'a duplicate of a pending charge must leave the counter at 3');
        Assert::assertSame(1, Order::query()->count(), 'five submissions of one cart must leave exactly one order');
    }
}
