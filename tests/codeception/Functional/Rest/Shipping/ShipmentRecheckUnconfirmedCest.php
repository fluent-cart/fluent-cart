<?php

namespace Tests\Functional\Rest\Shipping;

use FluentCart\App\App;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubRecheckCarrier;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\BuildsShipmentOrders;

/**
 * Each label purchase a carrier did not confirm is one entry in order meta,
 * keyed by its attempt id. POST .../recheck-unconfirmed replays one as a lookup
 * only; POST .../dismiss-unconfirmed drops one by hand.
 */
class ShipmentRecheckUnconfirmedCest
{
    use BuildsShipmentOrders;

    private $mails = [];
    private $mailCatcher;

    /** @var array[] args of every unconfirmed_purchase_resolved call, plus the DB transaction level */
    private $resolved = [];
    private $resolvedHook;

    public function _before(FunctionalTester $I): void
    {
        StubShippingCarrier::installRecheck();
        $this->mails       = [];
        $this->mailCatcher = function ($null, $atts) {
            $this->mails[] = (string) $atts['subject'];
            return true;
        };
        add_filter('pre_wp_mail', $this->mailCatcher, PHP_INT_MAX, 2);

        $this->resolved     = [];
        $this->resolvedHook = function ($attemptId, $carrierSlug, $order, $outcome) {
            $this->resolved[] = [$attemptId, $carrierSlug, (int) $order->id, $outcome, (int) App::db()->transactionLevel()];
        };
        add_action('fluent_cart/shipping/unconfirmed_purchase_resolved', $this->resolvedHook, 10, 4);
    }

    public function _after(FunctionalTester $I): void
    {
        remove_filter('pre_wp_mail', $this->mailCatcher, PHP_INT_MAX);
        remove_action('fluent_cart/shipping/unconfirmed_purchase_resolved', $this->resolvedHook, 10);
        StubShippingCarrier::uninstall();
    }

    private function unconfirmed(array $data = []): \WP_Error
    {
        return new \WP_Error('stub_purchase_unconfirmed', 'Carrier timed out', array_merge(['purchase_unconfirmed' => true], $data));
    }

    private function notFound(): \WP_Error
    {
        return new \WP_Error('stub_label_not_found', 'No label found', ['purchase_unconfirmed' => false]);
    }

    private function boughtLabel(): array
    {
        return [
            'tracking_number' => 'STUB123',
            'transaction_id'  => 'tx_1',
            'label_url'       => 'https://labels.example.test/tx_1.pdf',
            'cost'            => 750,
            'currency'        => 'USD',
        ];
    }

    private function postCreate(FunctionalTester $I, Order $order, $item, int $qty = 2, array $extra = []): array
    {
        return $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments', array_merge([
            'carrier_slug' => StubRecheckCarrier::SLUG,
            'items'        => [['order_item_id' => $item->id, 'qty' => $qty]],
        ], $extra));
    }

    private function createUnconfirmed(FunctionalTester $I, string $carrierSlug = StubRecheckCarrier::SLUG, string $notify = 'no'): array
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult = $this->unconfirmed();

        [$status, $body] = $this->postCreate($I, $order, $item, 2, [
            'carrier_slug'     => $carrierSlug,
            'selected_rate_id' => 'stub_rate',
            'notes'            => 'Fragile',
            'notify_customer'  => $notify,
            'shipped_at'       => '2026-10-01 09:30:00',
        ]);
        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        $listed = $body['unconfirmed_shipments'] ?? [];
        Assert::assertCount(1, $listed, 'the error carries the order\'s list');
        Assert::assertSame($carrierSlug === StubRecheckCarrier::SLUG, $listed[0]['can_recheck'], 'Check again is offered only when it can work');

        return [$order, $item, $listed[0]['id']];
    }

    private function recheck(FunctionalTester $I, Order $order, string $attemptId): array
    {
        return $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/recheck-unconfirmed', [
            'unconfirmed_id' => $attemptId,
        ]);
    }

    private function dismiss(FunctionalTester $I, Order $order, string $attemptId): array
    {
        return $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/dismiss-unconfirmed', [
            'unconfirmed_id' => $attemptId,
        ]);
    }

    private function savedRequests(Order $order): array
    {
        $saved = Order::find($order->id)->getMeta(ShipmentService::UNCONFIRMED_REQUESTS_META, []);

        return is_array($saved) ? $saved : [];
    }

    private function listedIds(array $body): array
    {
        return array_column($body['unconfirmed_shipments'] ?? [], 'id');
    }

    public function unconfirmedCreateSavesTheRequestAndWritesNoRow(FunctionalTester $I): void
    {
        [$order, $item, $attemptId] = $this->createUnconfirmed($I);

        Assert::assertSame(0, Shipment::query()->where('order_id', $order->id)->count(), 'no shipment row may be written');

        $saved = $this->savedRequests($order);
        Assert::assertSame([$attemptId], array_keys($saved), 'one entry, keyed by its attempt id');
        $request = $saved[$attemptId];
        Assert::assertSame($attemptId, $request['id']);
        Assert::assertSame(StubRecheckCarrier::SLUG, $request['carrier']);
        Assert::assertSame([['order_item_id' => (int) $item->id, 'qty' => 2]], $request['items']);
        Assert::assertSame('stub_rate', $request['selected_rate_id']);
        Assert::assertSame('Fragile', $request['notes']);
        Assert::assertFalse($request['notify_customer']);
        Assert::assertSame('2026-10-01 09:30:00', $request['shipped_at']);
        Assert::assertIsInt($request['at']);
        Assert::assertArrayNotHasKey('lookup_only', $request);
    }

    public function attemptIdReachesTheCarrierAndIsSavedBeforeTheCall(FunctionalTester $I): void
    {
        global $wpdb;
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);

        // Read on another connection: only a committed entry survives PHP dying mid-purchase.
        $committed = null;
        $passedId  = null;
        StubShippingCarrier::$onDispatch = function ($instance) use (&$committed, &$passedId, $order, $wpdb) {
            $passedId = $instance->data['unconfirmed_id'] ?? null;
            $other    = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            $row      = $other->query("SELECT meta_value FROM {$wpdb->prefix}fct_order_meta WHERE order_id = " . (int) $order->id . " AND meta_key = '" . ShipmentService::UNCONFIRMED_REQUESTS_META . "'")->fetch_assoc();
            $other->close();
            $committed = $row ? json_decode($row['meta_value'], true) : [];
        };
        StubShippingCarrier::$dispatchResult = $this->unconfirmed();

        [$status, $body] = $this->postCreate($I, $order, $item);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertIsString($passedId, 'the carrier gets the attempt id');
        Assert::assertSame([$passedId], array_keys((array) $committed), 'the entry is committed before the carrier is called');
        Assert::assertSame([$passedId], $this->listedIds($body));
    }

    public function definiteRefusalDropsTheAttempt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult = new \WP_Error('carrier_down', 'Carrier down');

        [$status, $body] = $this->postCreate($I, $order, $item);

        Assert::assertSame(422, $status);
        Assert::assertSame([], $this->savedRequests($order));
        Assert::assertSame([], $body['unconfirmed_shipments'] ?? null, 'the error carries the list');
    }

    public function twoSameCarrierUnconfirmedAttemptsAreBothKept(FunctionalTester $I): void
    {
        [$order, $item, $first] = $this->createUnconfirmed($I);

        [$status, $body] = $this->postCreate($I, $order, $item, 1, ['notes' => 'Second']);

        Assert::assertSame(422, $status);
        $saved = $this->savedRequests($order);
        Assert::assertCount(2, $saved, 'the second attempt must not overwrite the first');
        Assert::assertArrayHasKey($first, $saved);
        Assert::assertSame('Fragile', $saved[$first]['notes']);
        Assert::assertCount(2, $body['unconfirmed_shipments']);
    }

    public function purchaseNotSentDropsOnlyTheNewAttempt(FunctionalTester $I): void
    {
        [$order, $item, $first] = $this->createUnconfirmed($I);
        // The add-on refused before sending, because the first purchase is still pending.
        StubShippingCarrier::$dispatchResult = $this->unconfirmed(['purchase_sent' => false]);

        [$status, $body] = $this->postCreate($I, $order, $item, 1);

        Assert::assertSame(422, $status);
        Assert::assertTrue($body['purchase_unconfirmed'] ?? null);
        Assert::assertSame([$first], array_keys($this->savedRequests($order)), 'nothing was bought by the refused attempt');
        Assert::assertSame([$first], $this->listedIds($body));
    }

    public function successDropsOnlyItsOwnAttempt(FunctionalTester $I): void
    {
        [$order, $item, $older] = $this->createUnconfirmed($I);
        $item->quantity = 4;
        $item->save();

        // While this purchase waits on the carrier, another same-carrier purchase goes unconfirmed.
        $newer = null;
        StubShippingCarrier::$onDispatch = function () use ($order, $item, &$newer) {
            StubShippingCarrier::$onDispatch     = null;
            StubShippingCarrier::$dispatchResult = $this->unconfirmed();
            $result = ShipmentService::createShipment(Order::find($order->id), [
                'carrier_slug' => StubRecheckCarrier::SLUG,
                'notes'        => 'Newer',
                'items'        => [['order_item_id' => (int) $item->id, 'qty' => 1]],
            ]);
            Assert::assertInstanceOf(\WP_Error::class, $result);
            StubShippingCarrier::$dispatchResult = $this->boughtLabel();
        };
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();

        [$status, $body] = $this->postCreate($I, $order, $item, 1);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        $saved = $this->savedRequests($order);
        Assert::assertCount(2, $saved, 'the older and the newer attempt both stay');
        Assert::assertArrayHasKey($older, $saved);
        Assert::assertContains('Newer', array_column($saved, 'notes'));
        Assert::assertCount(2, $body['unconfirmed_shipments'], 'the success carries the list');
    }

    /**
     * Another tab sees the pre-dispatch entry and Check again records the label while Buy waits.
     *
     * @example [4]
     * @example [2]
     */
    public function buySettledByACheckAgainMeanwhileRecordsOnceAndVoidsNothing(FunctionalTester $I, \Codeception\Example $example): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem($example[0]);

        StubShippingCarrier::$onDispatch = function ($instance) use ($order) {
            StubShippingCarrier::$onDispatch = null;
            $recorded = ShipmentService::recheckUnconfirmedShipment(Order::find($order->id), $instance->data['unconfirmed_id']);
            Assert::assertInstanceOf(Shipment::class, $recorded);
        };
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();

        [$status, $body] = $this->postCreate($I, $order, $item);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('unconfirmed_label_settled', $body['code'] ?? null);
        Assert::assertStringContainsString('STUB123', $body['message'] ?? '', 'the admin can check which label came back');
        Assert::assertSame(1, Shipment::query()->where('order_id', $order->id)->count(), 'the label is recorded once');
        Assert::assertSame(2, (int) $item->fresh()->fulfilled_quantity);
        Assert::assertSame([], StubShippingCarrier::$cancelCalls, 'the recorded shipment uses this label');
    }

    /**
     * @example ["success"]
     * @example ["refused"]
     * @example ["not_sent"]
     * @example ["voided"]
     */
    public function everyDroppedAttemptTellsAddOnsAfterCommit(FunctionalTester $I, \Codeception\Example $example): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        $seenId = null;
        StubShippingCarrier::$onDispatch = function ($instance) use (&$seenId) {
            $seenId = $instance->data['unconfirmed_id'];
        };
        $results = [
            'success'  => $this->boughtLabel(),
            'refused'  => new \WP_Error('carrier_down', 'Carrier down'),
            'not_sent' => $this->unconfirmed(['purchase_sent' => false]),
            // Too long for the column, so the bought label is voided.
            'voided'   => array_merge($this->boughtLabel(), ['tracking_number' => str_repeat('9', 300)]),
        ];
        StubShippingCarrier::$dispatchResult = $results[$example[0]];

        $this->postCreate($I, $order, $item);

        $outcomes = ['success' => 'recorded', 'refused' => 'refused', 'not_sent' => 'refused', 'voided' => 'voided'];
        Assert::assertSame([[$seenId, StubRecheckCarrier::SLUG, (int) $order->id, $outcomes[$example[0]], 0]], $this->resolved);
        Assert::assertSame([], $this->savedRequests($order));
    }

    public function pendingAttemptIsQueryable(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);

        Assert::assertTrue(ShipmentService::isUnconfirmedAttemptPending(Order::find($order->id), $attemptId));
        Assert::assertFalse(ShipmentService::isUnconfirmedAttemptPending(Order::find($order->id), 'nope'));
    }

    public function nonStringAttemptIdIsRejected(FunctionalTester $I): void
    {
        [$order] = $this->createUnconfirmed($I);

        foreach (['recheck-unconfirmed', 'dismiss-unconfirmed'] as $path) {
            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/' . $path, ['unconfirmed_id' => ['a']]);
            Assert::assertSame(422, $status, $path . ' Body: ' . wp_json_encode($body));
            Assert::assertSame('invalid_unconfirmed_id', $body['code'] ?? null, $path);
        }
        Assert::assertCount(1, $this->savedRequests($order));
    }

    public function foundLabelWithoutTrackingIsNotNamed(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        Order::query()->where('id', $order->id)->update(['shipping_status' => 'delivered']);
        StubShippingCarrier::$dispatchResult = array_merge($this->boughtLabel(), ['tracking_number' => '']);

        [, $body] = $this->recheck($I, Order::find($order->id), $attemptId);

        Assert::assertSame('unconfirmed_label_not_recorded', $body['code'] ?? null);
        Assert::assertStringNotContainsString('label ,', $body['message'] ?? '');
    }

    public function carrierWithoutRecheckSavesAnEntryItCannotRecheck(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I, StubShippingCarrier::SLUG);

        Assert::assertSame(StubShippingCarrier::SLUG, $this->savedRequests($order)[$attemptId]['carrier'] ?? null, 'the note shows; only Mark resolved removes it');
    }

    public function carrierWithoutRecheckSavesItsAttemptBeforeTheCall(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        $seen = null;
        StubShippingCarrier::$onDispatch = function ($instance) use (&$seen, $order) {
            $seen = [$instance->data['unconfirmed_id'] ?? null, array_keys($this->savedRequests($order))];
        };
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();

        [$status, $body] = $this->postCreate($I, $order, $item, 1, ['carrier_slug' => StubShippingCarrier::SLUG]);

        Assert::assertSame(200, $status);
        Assert::assertIsString($seen[0]);
        Assert::assertSame([$seen[0]], $seen[1], 'saved before the carrier is called');
        Assert::assertSame([], $body['unconfirmed_shipments'], 'the success drops it');
    }

    public function carrierWithoutRecheckThrowingKeepsAnEntryItCannotRecheck(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$onDispatch = function () {
            throw new \RuntimeException('Connection reset after the purchase call');
        };

        [$status, $body] = $this->postCreate($I, $order, $item, 1, ['carrier_slug' => StubShippingCarrier::SLUG]);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertTrue($body['purchase_unconfirmed'] ?? null);
        Assert::assertCount(1, $body['unconfirmed_shipments']);
        Assert::assertFalse($body['unconfirmed_shipments'][0]['can_recheck'], 'only Mark resolved clears it');
    }

    public function manualShipmentSavesNoAttempt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);

        [$status, $body] = $this->postCreate($I, $order, $item, 1, ['carrier_slug' => 'manual']);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame([], $this->resolved, 'no attempt was saved, so none was settled');
        Assert::assertSame([], StubShippingCarrier::$dispatchLevels);
    }

    public function recheckFoundCreatesTheShipmentAndClearsTheRequest(FunctionalTester $I): void
    {
        [$order, $item, $attemptId] = $this->createUnconfirmed($I);

        $seen = [];
        StubShippingCarrier::$onDispatch = function ($instance) use (&$seen) {
            $seen = $instance->data;
        };
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertTrue($seen['lookup_only'] ?? null, 'the carrier must be told to look up, not buy');
        Assert::assertSame($attemptId, $seen['unconfirmed_id'] ?? null, 'the carrier gets the attempt id');
        Assert::assertSame('stub_rate', $seen['selected_rate_id'] ?? null, 'the saved request is replayed');
        Assert::assertSame('shipped', $body['order_shipping_status'] ?? null);
        Assert::assertSame([], $body['unconfirmed_shipments'] ?? null);

        $shipment = Shipment::query()->where('order_id', $order->id)->first();
        Assert::assertNotNull($shipment, 'the shipment must be created');
        Assert::assertSame('STUB123', $shipment->tracking_number);
        Assert::assertSame('Fragile', $shipment->notes);
        Assert::assertSame('2026-10-01 09:30:00', $shipment->shipped_at);
        Assert::assertSame('tx_1', $shipment->meta['transaction_id'] ?? null);
        Assert::assertSame(750, $shipment->meta['cost'] ?? null);
        Assert::assertSame(2, (int) $shipment->items[0]['qty']);
        Assert::assertSame([], $this->savedRequests($order), 'the saved request must be cleared');
        Assert::assertSame([[$attemptId, StubRecheckCarrier::SLUG, (int) $order->id, 'recorded', 0]], $this->resolved, 'add-ons hear of it after the commit');
    }

    public function recheckFoundLogsAndEmailsLikeANormalCreate(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$normalOrder, $normalItem] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();
        $this->mails = [];
        [$status] = $this->postCreate($I, $normalOrder, $normalItem, 2, ['notify_customer' => 'yes']);
        Assert::assertSame(200, $status);
        $normalMails = $this->mails;

        [$order, , $attemptId] = $this->createUnconfirmed($I, StubRecheckCarrier::SLUG, 'yes');
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();
        $this->mails = [];
        [$status, $body] = $this->recheck($I, $order, $attemptId);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));

        // Subjects carry each order's own number.
        $subjects = function (array $mails) {
            return preg_replace('/#\S+/', '#N', $mails);
        };
        Assert::assertNotEmpty($normalMails, 'a normal create with notify on emails the customer');
        Assert::assertSame($subjects($normalMails), $subjects($this->mails), 'the recheck sends the same customer email');

        $titles = function (Order $forOrder) {
            return Activity::query()->where('module_id', $forOrder->id)->orderBy('id')->pluck('title')->toArray();
        };
        Assert::assertNotEmpty($titles($normalOrder));
        Assert::assertSame($titles($normalOrder), $titles($order), 'the recheck logs the same activity');
    }

    public function recheckStillPendingKeepsTheRequest(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        $before = $this->savedRequests($order);

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('stub_purchase_unconfirmed', $body['code'] ?? null);
        Assert::assertTrue($body['purchase_unconfirmed'] ?? null);
        Assert::assertSame([$attemptId], $this->listedIds($body));
        Assert::assertSame($before, $this->savedRequests($order), 'the saved request is kept as it was');
        Assert::assertSame(0, Shipment::query()->where('order_id', $order->id)->count());
        Assert::assertSame([], $this->resolved);
    }

    /**
     * @example ["null"]
     * @example ["string"]
     * @example ["error"]
     */
    public function recheckWithAnAnswerOutsideTheContractKeepsTheRequest(FunctionalTester $I, \Codeception\Example $example): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        $answers = [
            'null'   => null,
            'string' => 'found it',
            'error'  => new \WP_Error('stub_http_error', 'Lookup failed'),
        ];
        StubShippingCarrier::$dispatchResult = $answers[$example[0]];

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertTrue($body['purchase_unconfirmed'] ?? null, 'treated as still unknown');
        Assert::assertSame([$attemptId], array_keys($this->savedRequests($order)));
        Assert::assertSame(0, Shipment::query()->where('order_id', $order->id)->count(), 'never a shipment with no label');
    }

    // The add-on writes its hold only after its first calls, so a fresh Buy may not be findable yet.
    public function recheckNotFoundOnAPurchaseThatMayStillBeRunningKeepsIt(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        StubShippingCarrier::$dispatchResult = $this->notFound();

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertTrue($body['purchase_unconfirmed'] ?? null, 'still unknown');
        Assert::assertSame([$attemptId], array_keys($this->savedRequests($order)));
        Assert::assertSame([$attemptId], $this->listedIds($body));
        Assert::assertSame([], $this->resolved);
    }

    public function carrierThrowingMidPurchaseKeepsTheAttempt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$onDispatch = function () {
            throw new \RuntimeException('Connection reset after the purchase call');
        };

        [$status, $body] = $this->postCreate($I, $order, $item);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('carrier_dispatch_failed', $body['code'] ?? null);
        Assert::assertTrue($body['purchase_unconfirmed'] ?? null, 'a label may have been bought before the throw');
        Assert::assertCount(1, $this->savedRequests($order));
        Assert::assertSame([], $this->resolved);
    }

    public function recheckNotFoundClearsTheRequest(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        $this->ageAttempts($order);
        StubShippingCarrier::$dispatchResult = $this->notFound();

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('stub_label_not_found', $body['code'] ?? null);
        Assert::assertArrayNotHasKey('purchase_unconfirmed', $body);
        Assert::assertSame([], $body['unconfirmed_shipments'] ?? null);
        Assert::assertSame([], $this->savedRequests($order), 'nothing was found, so Buy may unlock');
        Assert::assertSame(0, Shipment::query()->where('order_id', $order->id)->count());
        Assert::assertSame([[$attemptId, StubRecheckCarrier::SLUG, (int) $order->id, 'not_found', 0]], $this->resolved);
    }

    public function recheckNotFoundDropsOnlyThatAttempt(FunctionalTester $I): void
    {
        [$order, $item, $first] = $this->createUnconfirmed($I);
        $this->postCreate($I, $order, $item, 1, ['notes' => 'Second']);
        $this->ageAttempts($order);
        StubShippingCarrier::$dispatchResult = $this->notFound();

        $this->recheck($I, $order, $first);

        $saved = $this->savedRequests($order);
        Assert::assertCount(1, $saved);
        Assert::assertSame('Second', array_values($saved)[0]['notes']);
    }

    public function recheckAfterTheUnitsShippedKeepsTheEntryAndNamesTheLabel(FunctionalTester $I): void
    {
        [$order, $item, $attemptId] = $this->createUnconfirmed($I);
        // The admin recorded the units by hand meanwhile.
        StubShippingCarrier::$dispatchResult = null;
        Assert::assertInstanceOf(Shipment::class, ShipmentService::createShipment(Order::find($order->id), [
            'carrier_slug' => 'manual',
            'items'        => [['order_item_id' => (int) $item->id, 'qty' => 2]],
        ]));
        StubShippingCarrier::$dispatchLevels = [];
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('unconfirmed_label_not_recorded', $body['code'] ?? null);
        Assert::assertStringContainsString('STUB123', $body['message'] ?? '', 'the admin can record the found label by hand');
        Assert::assertCount(1, StubShippingCarrier::$dispatchLevels, 'the carrier is asked even though the order changed');
        Assert::assertSame([], StubShippingCarrier::$cancelCalls, 'a found label is never voided');
        Assert::assertSame([$attemptId], array_keys($this->savedRequests($order)));
        Assert::assertSame(1, Shipment::query()->where('order_id', $order->id)->count());
        Assert::assertSame([], $this->resolved);
    }

    public function recheckAfterDeliveryKeepsTheEntryAndNamesTheLabel(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        Order::query()->where('id', $order->id)->update(['shipping_status' => 'delivered']);
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();

        [$status, $body] = $this->recheck($I, Order::find($order->id), $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('unconfirmed_label_not_recorded', $body['code'] ?? null);
        Assert::assertStringContainsString('STUB123', $body['message'] ?? '');
        Assert::assertSame([$attemptId], array_keys($this->savedRequests($order)));
        Assert::assertSame(0, Shipment::query()->where('order_id', $order->id)->count());
    }

    public function carrierWithoutRecheckIsRefusedAndNeverCalled(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        // As an older core version left it: keyed by carrier, no id.
        $order->updateMeta(ShipmentService::UNCONFIRMED_REQUESTS_META, [
            StubShippingCarrier::SLUG => [
                'carrier_slug' => StubShippingCarrier::SLUG,
                'items'        => [['order_item_id' => (int) $item->id, 'qty' => 1]],
                'at'           => time(),
            ],
        ]);
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();

        [$status, $body] = $this->recheck($I, $order, 'legacy-' . StubShippingCarrier::SLUG);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('recheck_unsupported', $body['code'] ?? null);
        Assert::assertSame([], StubShippingCarrier::$dispatchLevels, 'the carrier must never be called: it could buy a new label');
        Assert::assertSame(0, Shipment::query()->where('order_id', $order->id)->count());
    }

    public function legacyEntryWithAnIdIsListedAndRecheckedByIt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        $order->updateMeta(ShipmentService::UNCONFIRMED_REQUESTS_META, [
            StubRecheckCarrier::SLUG => [
                'items'           => [['order_item_id' => (int) $item->id, 'qty' => 1]],
                'notify_customer' => false,
                'at'              => 5,
                'unconfirmed_id'  => 'old-id',
            ],
        ]);
        [, $page] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id);
        Assert::assertSame('old-id', $page['order']['unconfirmed_shipments'][0]['id'] ?? null);
        Assert::assertSame(StubRecheckCarrier::SLUG, $page['order']['unconfirmed_shipments'][0]['carrier'] ?? null);

        $seen = null;
        StubShippingCarrier::$onDispatch = function ($instance) use (&$seen) {
            $seen = $instance->data['unconfirmed_id'] ?? null;
        };
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();

        [$status, $body] = $this->recheck($I, $order, 'old-id');

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('old-id', $seen, 'the add-on keyed its hold by this id');
        Assert::assertSame([], $this->savedRequests($order));
    }

    /**
     * The carrier may answer not-found to one check and found to another; only one may run.
     *
     * @example ["found"]
     * @example ["not_found"]
     */
    public function overlappingRechecksRecordTheLabelOnce(FunctionalTester $I, \Codeception\Example $example): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(4);
        $order->updateMeta(ShipmentService::UNCONFIRMED_REQUESTS_META, [
            StubRecheckCarrier::SLUG => [
                'items'            => [['order_item_id' => (int) $item->id, 'qty' => 1]],
                'selected_rate_id' => 'stub_rate',
                'notify_customer'  => false,
                'at'               => time() - 600,
            ],
        ]);
        $attemptId = 'legacy-' . StubRecheckCarrier::SLUG;
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();

        // A second Check again arrives while this one waits on the carrier.
        $second = null;
        StubShippingCarrier::$onDispatch = function () use ($order, $attemptId, &$second, $example) {
            StubShippingCarrier::$onDispatch = null;
            if ($example[0] === 'not_found') {
                StubShippingCarrier::$dispatchResult = $this->notFound();
            }
            $second = ShipmentService::recheckUnconfirmedShipment(Order::find($order->id), $attemptId);
            StubShippingCarrier::$dispatchResult = $this->boughtLabel();
        };

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertInstanceOf(\WP_Error::class, $second);
        Assert::assertSame('unconfirmed_check_running', $second->get_error_code());
        Assert::assertTrue($second->get_error_data()['purchase_unconfirmed'] ?? null, 'still unknown to the second caller');
        Assert::assertCount(1, StubShippingCarrier::$dispatchLevels, 'the second check never reaches the carrier');
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Shipment::query()->where('order_id', $order->id)->count(), 'the label is recorded once');
        Assert::assertSame(1, (int) $item->fresh()->fulfilled_quantity);
        Assert::assertSame([], StubShippingCarrier::$cancelCalls);
        Assert::assertSame([], $this->savedRequests($order));
        Assert::assertCount(1, $this->resolved, 'resolved once');
    }

    public function anUnknownAnswerReleasesTheCheckForTheNextOne(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        StubShippingCarrier::$dispatchLevels = [];

        $this->recheck($I, $order, $attemptId);
        [, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame('stub_purchase_unconfirmed', $body['code'] ?? null);
        Assert::assertCount(2, StubShippingCarrier::$dispatchLevels, 'the claim was released');
    }

    /**
     * @example [-1, "stub_purchase_unconfirmed"]
     * @example [60, "unconfirmed_check_running"]
     */
    public function aClaimHoldsOnlyUntilItExpires(FunctionalTester $I, \Codeception\Example $example): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        $saved = $this->savedRequests($order);
        $saved[$attemptId]['checking_until'] = time() + $example[0];
        $saved[$attemptId]['check_token']    = 'crashed';
        Order::find($order->id)->updateMeta(ShipmentService::UNCONFIRMED_REQUESTS_META, $saved);

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(422, $status);
        Assert::assertSame($example[1], $body['code'] ?? null);
        Assert::assertTrue($body['purchase_unconfirmed'] ?? null);
    }

    public function dismissRefusesWhileACheckRuns(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        $this->ageAttempts($order);
        $saved = $this->savedRequests($order);
        $saved[$attemptId]['checking_until'] = time() + 60;
        Order::find($order->id)->updateMeta(ShipmentService::UNCONFIRMED_REQUESTS_META, $saved);

        [$status, $body] = $this->dismiss($I, $order, $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('unconfirmed_check_running', $body['code'] ?? null);
        Assert::assertSame([$attemptId], array_keys($this->savedRequests($order)));
    }

    public function staleNotFoundRecheckKeepsANewerSavedRequest(FunctionalTester $I): void
    {
        [$order, $item, $attemptId] = $this->createUnconfirmed($I);
        $this->ageAttempts($order);

        // While this Check again waits on the carrier, a newer purchase times out and is saved.
        StubShippingCarrier::$onDispatch = function () use ($order, $item) {
            StubShippingCarrier::$onDispatch    = null;
            StubShippingCarrier::$dispatchResult = $this->unconfirmed();
            $newer = ShipmentService::createShipment(Order::find($order->id), [
                'carrier_slug' => StubRecheckCarrier::SLUG,
                'notes'        => 'Newer',
                'items'        => [['order_item_id' => (int) $item->id, 'qty' => 1]],
            ]);
            Assert::assertInstanceOf(\WP_Error::class, $newer);
            StubShippingCarrier::$dispatchResult = $this->notFound();
        };

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('stub_label_not_found', $body['code'] ?? null);
        $saved = $this->savedRequests($order);
        Assert::assertSame(['Newer'], array_column($saved, 'notes'), 'the newer pending purchase must survive');
        Assert::assertCount(1, $body['unconfirmed_shipments'], 'the client keeps the newer entry locked');
    }

    public function foundRecheckLeavesANewerSameCarrierAttempt(FunctionalTester $I): void
    {
        [$order, $item, $attemptId] = $this->createUnconfirmed($I);
        $item->quantity = 3;
        $item->save();

        StubShippingCarrier::$onDispatch = function () use ($order, $item) {
            StubShippingCarrier::$onDispatch    = null;
            StubShippingCarrier::$dispatchResult = $this->unconfirmed();
            ShipmentService::createShipment(Order::find($order->id), [
                'carrier_slug' => StubRecheckCarrier::SLUG,
                'notes'        => 'Newer',
                'items'        => [['order_item_id' => (int) $item->id, 'qty' => 1]],
            ]);
            StubShippingCarrier::$dispatchResult = $this->boughtLabel();
        };

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Shipment::query()->where('order_id', $order->id)->count());
        Assert::assertSame(['Newer'], array_column($this->savedRequests($order), 'notes'));
    }

    public function unknownAttemptIsRefused(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();

        [$status, $body] = $this->recheck($I, $order, 'nope');

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('no_unconfirmed_request', $body['code'] ?? null);
        Assert::assertSame([], $body['unconfirmed_shipments'] ?? null);
        Assert::assertSame([], StubShippingCarrier::$dispatchLevels);
    }

    public function dismissRefusesAPurchaseThatMayStillBeRunning(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);

        [$status, $body] = $this->dismiss($I, $order, $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('unconfirmed_purchase_running', $body['code'] ?? null);
        Assert::assertSame([$attemptId], array_keys($this->savedRequests($order)));
        Assert::assertSame([$attemptId], $this->listedIds($body));
        Assert::assertSame([], $this->resolved);
    }

    /** Moves every saved attempt past the window a purchase can still be running in. */
    private function ageAttempts(Order $order): void
    {
        $saved = $this->savedRequests($order);
        foreach ($saved as $attemptId => $entry) {
            $saved[$attemptId]['at'] = time() - 600;
        }
        Order::find($order->id)->updateMeta(ShipmentService::UNCONFIRMED_REQUESTS_META, $saved);
    }

    public function dismissDropsTheEntryLogsAndTellsAddOns(FunctionalTester $I): void
    {
        [$order, $item, $attemptId] = $this->createUnconfirmed($I);
        $this->postCreate($I, $order, $item, 1, ['notes' => 'Second']);
        $this->ageAttempts($order);

        [$status, $body] = $this->dismiss($I, $order, $attemptId);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertCount(1, $body['unconfirmed_shipments'] ?? []);
        Assert::assertNotContains($attemptId, $this->listedIds($body));
        Assert::assertSame(['Second'], array_column($this->savedRequests($order), 'notes'));
        Assert::assertSame([[$attemptId, StubRecheckCarrier::SLUG, (int) $order->id, 'dismissed', 0]], $this->resolved);
        Assert::assertSame([], StubShippingCarrier::$cancelCalls);

        $log = Activity::query()->where('module_id', $order->id)->orderBy('id', 'desc')->first();
        Assert::assertStringContainsString('Stub Recheck Carrier', (string) $log->content);
        Assert::assertStringContainsString('marked resolved', (string) $log->content);

        [$status, $body] = $this->dismiss($I, $order, $attemptId);
        Assert::assertSame(422, $status);
        Assert::assertSame('no_unconfirmed_request', $body['code'] ?? null);
    }

    public function concurrentUnconfirmedCarriersKeepBothSavedRequests(FunctionalTester $I): void
    {
        global $wpdb;
        $other = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        $orderId    = 0;
        $otherSaved = null;

        // Another carrier's request saves its entry after this one has read the map.
        $hook = function () use (&$orderId, &$otherSaved, $other) {
            if ($otherSaved === null && $orderId) {
                $otherSaved = $this->saveFromOtherConnection($other, $orderId, 'other_carrier');
            }
        };
        OrderMeta::saving($hook);

        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        $orderId = (int) $order->id;
        StubShippingCarrier::$dispatchResult = $this->unconfirmed();

        [$status] = $this->postCreate($I, $order, $item, 1);
        $orderId = 0;
        Assert::assertSame(422, $status);

        // A request blocked by the order lock resumes once this one commits.
        if ($otherSaved === false) {
            Assert::assertTrue($this->saveFromOtherConnection($other, (int) $order->id, 'other_carrier'));
        }
        $other->close();

        $rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_meta WHERE order_id = %d AND meta_key = %s",
            $order->id,
            ShipmentService::UNCONFIRMED_REQUESTS_META
        ));
        Assert::assertSame(1, $rows, 'one meta row per order');
        $carriers = array_column(ShipmentService::unconfirmedShipments(Order::find($order->id)), 'carrier');
        sort($carriers);
        Assert::assertSame(['other_carrier', StubRecheckCarrier::SLUG], $carriers, 'neither carrier\'s entry may be lost');
    }

    /**
     * Mirrors the service's lock-then-read-modify-write on a second connection,
     * writing the pre-v2 shape; false when the order row lock is held elsewhere.
     */
    private function saveFromOtherConnection(\mysqli $db, int $orderId, string $carrierSlug): bool
    {
        global $wpdb;
        $metaTable = $wpdb->prefix . 'fct_order_meta';
        $key       = ShipmentService::UNCONFIRMED_REQUESTS_META;

        $db->query('START TRANSACTION');
        // WordPress turns mysqli exceptions off, so a lock wait timeout returns false.
        if (!$db->query("SELECT id FROM {$wpdb->prefix}fct_orders WHERE id = {$orderId} FOR UPDATE")) {
            $db->query('ROLLBACK');
            return false;
        }

        $row = $db->query("SELECT id, meta_value FROM {$metaTable} WHERE order_id = {$orderId} AND meta_key = '{$key}' LIMIT 1")->fetch_assoc();
        $map = $row ? (array) json_decode($row['meta_value'], true) : [];
        $map[$carrierSlug] = ['items' => [], 'at' => 1];
        $value = $db->real_escape_string(wp_json_encode($map));

        if ($row) {
            $db->query("UPDATE {$metaTable} SET meta_value = '{$value}' WHERE id = " . (int) $row['id']);
        } else {
            $db->query("INSERT INTO {$metaTable} (order_id, meta_key, meta_value) VALUES ({$orderId}, '{$key}', '{$value}')");
        }
        $db->query('COMMIT');

        return true;
    }

    /** Overwrites the attempt's claim, as a newer Check again taking over an expired one. */
    private function claimAs(Order $order, string $attemptId, string $token): void
    {
        $saved = $this->savedRequests($order);
        $saved[$attemptId]['checking_until'] = time() + 60;
        $saved[$attemptId]['check_token']    = $token;
        Order::find($order->id)->updateMeta(ShipmentService::UNCONFIRMED_REQUESTS_META, $saved);
    }

    public function expiredCheckNotFoundLeavesTheAttemptToTheCheckThatTookOver(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        $this->ageAttempts($order);
        StubShippingCarrier::$dispatchResult = $this->notFound();

        // This check outlives its claim; another takes the attempt over before the carrier answers.
        StubShippingCarrier::$onDispatch = function () use ($order, $attemptId) {
            StubShippingCarrier::$onDispatch = null;
            $this->claimAs($order, $attemptId, 'claim-b');
        };

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('unconfirmed_check_running', $body['code'] ?? null);
        Assert::assertTrue($body['purchase_unconfirmed'] ?? null, 'still unknown');
        Assert::assertSame('claim-b', $this->savedRequests($order)[$attemptId]['check_token'] ?? null, 'the attempt and its new claim survive');
        Assert::assertSame([], $this->resolved, 'the add-on keeps its hold');

        // The check that took over finds the label.
        $seen = null;
        StubShippingCarrier::$onDispatch = function ($instance) use (&$seen) {
            $seen = $instance->data;
        };
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();
        $data = array_merge(
            array_diff_key($this->savedRequests($order)[$attemptId], array_flip(['id', 'carrier', 'at', 'checking_until', 'check_token'])),
            ['carrier_slug' => StubRecheckCarrier::SLUG, 'lookup_only' => true, 'unconfirmed_id' => $attemptId, 'check_token' => 'claim-b']
        );
        $shipment = ShipmentService::createShipment(Order::find($order->id), $data);

        Assert::assertInstanceOf(Shipment::class, $shipment);
        Assert::assertArrayNotHasKey('check_token', (array) $seen, 'the claim token never reaches the carrier');
        Assert::assertSame(1, Shipment::query()->where('order_id', $order->id)->count(), 'recorded once');
        Assert::assertSame([], $this->savedRequests($order));
        Assert::assertSame([[$attemptId, StubRecheckCarrier::SLUG, (int) $order->id, 'recorded', 0]], $this->resolved);
    }

    public function expiredCheckFindingTheLabelLeavesItToTheCheckThatTookOver(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        StubShippingCarrier::$dispatchResult = $this->boughtLabel();
        StubShippingCarrier::$onDispatch = function () use ($order, $attemptId) {
            StubShippingCarrier::$onDispatch = null;
            $this->claimAs($order, $attemptId, 'claim-b');
        };

        [$status, $body] = $this->recheck($I, $order, $attemptId);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('unconfirmed_check_running', $body['code'] ?? null);
        Assert::assertTrue($body['purchase_unconfirmed'] ?? null);
        Assert::assertSame(0, Shipment::query()->where('order_id', $order->id)->count(), 'only the claim holder records');
        Assert::assertSame('claim-b', $this->savedRequests($order)[$attemptId]['check_token'] ?? null);
        Assert::assertSame([], StubShippingCarrier::$cancelCalls, 'the label is never voided');
        Assert::assertSame([], $this->resolved);
    }

    public function dismissRefusesACheckClaimedAfterItsFirstRead(FunctionalTester $I): void
    {
        global $wpdb;
        [$order, , $attemptId] = $this->createUnconfirmed($I);
        $this->ageAttempts($order);

        // A Check again claims on another connection just before dismiss takes the order lock.
        $metaTable = $wpdb->prefix . 'fct_order_meta';
        $claim     = function ($sql) use (&$claim, $order, $attemptId, $metaTable, $wpdb) {
            if (stripos($sql, 'for update') === false || strpos($sql, $wpdb->prefix . 'fct_orders') === false) {
                return $sql;
            }
            remove_filter('query', $claim);
            $other = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            $key   = ShipmentService::UNCONFIRMED_REQUESTS_META;
            $row   = $other->query("SELECT id, meta_value FROM {$metaTable} WHERE order_id = " . (int) $order->id . " AND meta_key = '{$key}' LIMIT 1")->fetch_assoc();
            $map   = json_decode($row['meta_value'], true);
            $map[$attemptId]['checking_until'] = time() + 60;
            $map[$attemptId]['check_token']    = 'claim-b';
            $other->query("UPDATE {$metaTable} SET meta_value = '" . $other->real_escape_string(wp_json_encode($map)) . "' WHERE id = " . (int) $row['id']);
            $other->close();
            return $sql;
        };
        add_filter('query', $claim);

        [$status, $body] = $this->dismiss($I, $order, $attemptId);
        remove_filter('query', $claim);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('unconfirmed_check_running', $body['code'] ?? null);
        Assert::assertSame([$attemptId], array_keys($this->savedRequests($order)), 'the attempt is kept');
        Assert::assertSame([], $this->resolved);
    }

    public function orderPayloadListsUnconfirmedShipments(FunctionalTester $I): void
    {
        [$order, , $attemptId] = $this->createUnconfirmed($I);

        [$status, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        $list = $body['order']['unconfirmed_shipments'] ?? null;
        Assert::assertIsArray($list);
        Assert::assertCount(1, $list);
        Assert::assertSame($attemptId, $list[0]['id']);
        Assert::assertSame(StubRecheckCarrier::SLUG, $list[0]['carrier']);
        Assert::assertSame('Stub Recheck Carrier', $list[0]['carrier_name']);
        Assert::assertIsInt($list[0]['at']);
        Assert::assertTrue($list[0]['can_recheck']);
    }
}
