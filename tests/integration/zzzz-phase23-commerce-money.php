<?php
/**
 * Phase 23 exact-cent checkout, tax, coupon, refund, and stock contracts.
 */

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\AdminOrderProcessor;
use FluentCart\App\Helpers\CheckoutProcessor;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Listeners\UpdateStock;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface;
use FluentCart\App\Modules\Tax\TaxCalculator;
use FluentCart\App\Services\Coupon\CouponServiceAdmin;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Payments\Refund;
use FluentCart\Framework\Support\Collection;

class FcPhase23InertGateway implements PaymentGatewayInterface
{
    /** @var array<int,int> */
    public $amounts = [];

    public function has(string $feature): bool
    {
        return $feature === 'payment';
    }

    public function meta(): array
    {
        return [
            'title'       => 'Phase 23 inert gateway',
            'route'       => 'phase23_inert',
            'slug'        => 'phase23_inert',
            'description' => 'Test-only inert gateway',
            'logo'        => '',
            'icon'        => '',
            'brand_color' => '#000000',
            'upcoming'    => false,
            'status'      => true,
        ];
    }

    public function getMeta($key = '')
    {
        $meta = $this->meta();

        return $key === '' ? $meta : ($meta[$key] ?? '');
    }

    public function validatePaymentMethod($status): array
    {
        return ['isValid' => true, 'reason' => ''];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $amount = (int) $paymentInstance->order->total_amount;
        $this->amounts[] = $amount;

        return [
            'status' => 'success',
            'amount' => $amount,
            'mode'   => 'inert',
        ];
    }

    public function handleIPN()
    {
        return null;
    }

    public function getOrderInfo(array $data)
    {
        return [];
    }

    public function fields()
    {
        return [];
    }
}

$phase23CartItem = function ($id, $unitPrice, $quantity, array $extra = []) {
    $defaults = [
        'id'               => (int) $id,
        'object_id'        => (int) $id,
        'post_id'          => 0,
        'quantity'         => (int) $quantity,
        'unit_price'       => (int) $unitPrice,
        'subtotal'         => (int) $unitPrice * (int) $quantity,
        'product_title'    => 'Phase 23 product ' . (int) $id,
        'variation_title'  => 'Phase 23 variation ' . (int) $id,
        'fulfillment_type' => 'digital',
        'coupon_discount'  => 0,
        'manual_discount'  => 0,
        'tax_amount'       => 0,
        'shipping_charge'  => 0,
        'is_custom'        => true,
        'other_info'       => [
            'payment_type'   => 'onetime',
            'item_attributes' => [],
            'variation_type' => 'simple',
        ],
        'line_meta'        => [],
    ];

    return array_replace_recursive($defaults, $extra);
};

$phase23Reflect = function ($object, $property) {
    $reflection = new ReflectionProperty(get_class($object), (string) $property);
    $reflection->setAccessible(true);

    return $reflection->getValue($object);
};

$phase23RestoreOption = function ($key, $previous, $missing) {
    if ($previous === $missing) {
        delete_option($key);
        return;
    }

    update_option($key, $previous, false);
};

return [
    [
        'id'            => 'phase23-checkout-processor-exact-cents',
        'name'          => 'CheckoutProcessor conserves exact line cents through exclusive order totals',
        'phase'         => 23,
        'known_failure' => false,
        'run'           => function () use ($phase23CartItem) {
            try {
                $customer = FcFixture::customer();
                $order = FcFixture::order([
                    'total_amount' => 1,
                    'subtotal'     => 1,
                ]);
                $items = [
                    $phase23CartItem(230001, 1001, 1, [
                        'coupon_discount' => 1,
                        'tax_amount'      => 1,
                    ]),
                    $phase23CartItem(230002, 333, 3, [
                        'manual_discount' => 1,
                        'tax_amount'      => 2,
                    ]),
                ];

                $processor = new CheckoutProcessor($items, [
                    'customer_id'       => (int) $customer->id,
                    'tax_total'         => 3,
                    'tax_behavior'      => 1,
                    'shipping_tax'      => 1,
                    'shipping_charge'   => 2,
                    'payment_method'    => '',
                    'note'              => FcFixture::orderMarker(),
                    'is_locked'         => false,
                ]);
                $adjusted = $processor->createDraftOrder($order);
                FcTest::assertSame((int) $order->id, (int) $adjusted->id, 'exact owned Order adjusted');

                $stored = FcFixture::reloadOrder((int) $order->id);
                $storedItems = OrderItem::query()
                    ->where('order_id', (int) $order->id)
                    ->orderBy('id')
                    ->get();
                $lineTotals = array_map('intval', $storedItems->pluck('line_total')->toArray());
                $transaction = OrderTransaction::query()
                    ->where('order_id', (int) $order->id)
                    ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                    ->first();

                FcTest::assertSame([1000, 998], $lineTotals, 'line totals retain every discount cent');
                FcTest::assertSame(1998, array_sum($lineTotals), 'line sum conserves exact cents');
                FcTest::assertSame(2000, (int) $stored->subtotal, 'stored subtotal');
                FcTest::assertSame(1, (int) $stored->coupon_discount_total, 'coupon cents');
                FcTest::assertSame(1, (int) $stored->manual_discount_total, 'manual cents');
                FcTest::assertSame(3, (int) $stored->tax_total, 'exclusive tax cents');
                FcTest::assertSame(1, (int) $stored->shipping_tax, 'shipping tax cent');
                FcTest::assertSame(2, (int) $stored->shipping_total, 'shipping cents');
                FcTest::assertSame(2004, (int) $stored->total_amount, 'payable exact cents');
                FcTest::assert($transaction !== null, 'pending inert transaction exists');
                FcTest::assertSame(2004, (int) $transaction->total, 'transaction exact cents');
                FcTest::assertSame(Status::PAYMENT_PENDING, (string) $transaction->status, 'transaction remains pending');
                FcTest::assertSame(0, (int) $stored->total_paid, 'no payment completed');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase23-admin-order-processor-exact-cents',
        'name'          => 'AdminOrderProcessor preserves boundary cents before any draft write',
        'phase'         => 23,
        'known_failure' => false,
        'run'           => function () use ($phase23CartItem, $phase23Reflect) {
            try {
                FcFixture::customer();
                $inertOrder = FcFixture::order([
                    'subtotal'     => 2000,
                    'total_amount' => 2000,
                ]);
                FcTest::assertSame(2000, (int) FcFixture::reloadOrder($inertOrder->id)->total_amount, 'inert Order canary');

                $items = [
                    $phase23CartItem(230011, 1001, 1, [
                        'discount_total' => 1,
                    ]),
                    $phase23CartItem(230012, 333, 3, [
                        'manual_discount' => 1,
                    ]),
                ];
                $processor = new AdminOrderProcessor($items, ['shipping_total' => 2]);
                $orderData = $phase23Reflect($processor, 'orderData');
                $formatted = $phase23Reflect($processor, 'formattedIOrderItems');
                $lineTotals = array_map(function ($item) {
                    return (int) $item['subtotal'] - (int) $item['discount_total'];
                }, $formatted);

                FcTest::assertSame([1000, 998], $lineTotals, 'admin line totals');
                FcTest::assertSame(1998, array_sum($lineTotals), 'admin line sum exact cents');
                FcTest::assertSame(2000, (int) $orderData['subtotal'], 'admin subtotal cents');
                FcTest::assertSame(1, (int) $orderData['coupon_discount_total'], 'admin coupon cent');
                FcTest::assertSame(1, (int) $orderData['manual_discount_total'], 'admin manual cent');
                FcTest::assertSame(2, (int) $orderData['shipping_total'], 'admin shipping cents');
                FcTest::assertSame(2000, (int) $orderData['total_amount'], 'admin payable exact cents');
                FcTest::assertSame(0, (int) $inertOrder->total_paid, 'inert Order remains unpaid');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase23-tax-location-inclusion-rounding',
        'name'          => 'TaxCalculator resolves location, inclusion, and half-cent rounding exactly',
        'phase'         => 23,
        'known_failure' => false,
        'run'           => function () use ($phase23RestoreOption) {
            $option = 'fluent_cart_tax_configuration_settings';
            $missing = '__phase23_missing_' . wp_generate_password(20, false, false);
            $previous = get_option($option, $missing);

            try {
                $product = FcDomainFixture::product([
                    'item_price'       => 5,
                    'fulfillment_type' => 'digital',
                    'other_info'       => [],
                ]);
                FcFixture::customer();
                $inertOrder = FcDomainFixture::orderWithItem(
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    2,
                    ['total_amount' => 11, 'subtotal' => 10]
                );
                FcTest::assertSame(11, (int) $inertOrder->total_amount, 'tax inert Order canary');

                $standard = TaxClass::query()->where('slug', 'standard')->first();
                FcTest::assert($standard !== null, 'standard TaxClass exists');
                FcFixture::taxRate((int) $standard->id);

                update_option($option, [
                    'enable_tax'    => 'yes',
                    'tax_inclusion' => 'excluded',
                ], false);
                TaxCalculator::resetCache();

                $base = [
                    'post_id'          => (int) $product['post']->ID,
                    'object_id'        => (int) $product['variation']->id,
                    'quantity'         => 1,
                    'unit_price'       => 5,
                    'subtotal'         => 5,
                    'discount_total'   => 0,
                    'shipping_charge'  => 0,
                    'other_info'       => ['payment_type' => 'onetime'],
                    'line_meta'        => [],
                ];
                $lines = [
                    array_merge($base, ['id' => 230021]),
                    array_merge($base, ['id' => 230022]),
                ];
                $location = [
                    'country'      => 'XZ',
                    'state'        => 'ST',
                    'postcode'     => '1500',
                    'tax_rounding' => 'item',
                ];

                $itemRounded = new TaxCalculator($lines, $location);
                FcTest::assertSame(2, $itemRounded->getTotalTax(), 'two half-cent lines round per item');
                FcTest::assertSame(2, $itemRounded->getExclusiveTaxTotal(), 'exclusive half-cent total');
                FcTest::assertSame(1, $itemRounded->getTaxBehaviorValue(), 'exclusive behavior');

                TaxCalculator::resetCache();
                $subtotalRounded = new TaxCalculator(
                    $lines,
                    array_merge($location, ['tax_rounding' => 'subtotal'])
                );
                FcTest::assertSame(1, $subtotalRounded->getTotalTax(), 'subtotal rounds accumulated cent once');

                TaxCalculator::resetCache();
                $wrongPostcode = new TaxCalculator(
                    $lines,
                    array_merge($location, ['postcode' => '9999'])
                );
                FcTest::assertSame(0, $wrongPostcode->getTotalTax(), 'postcode mismatch has zero tax cents');

                update_option($option, [
                    'enable_tax'    => 'yes',
                    'tax_inclusion' => 'included',
                ], false);
                TaxCalculator::resetCache();
                $inclusiveLine = array_merge($base, [
                    'id'         => 230023,
                    'unit_price' => 11,
                    'subtotal'   => 11,
                ]);
                $inclusive = new TaxCalculator([$inclusiveLine], $location);
                FcTest::assertSame(1, $inclusive->getTotalTax(), 'inclusive 11-cent boundary tax');
                FcTest::assertSame(2, $inclusive->getTaxBehaviorValue(), 'inclusive behavior');
                FcTest::assertSame(0, $inclusive->getExclusiveTaxTotal(), 'inclusive cents are not additive');
                FcTest::assertSame(0, (int) $inertOrder->total_paid, 'tax canary remains unpaid');
            } finally {
                $phase23RestoreOption($option, $previous, $missing);
                TaxCalculator::resetCache();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase23-coupon-cap-and-minimum-cents',
        'name'          => 'Coupon concerns enforce exact-cent minimums and caps on line totals',
        'phase'         => 23,
        'known_failure' => false,
        'run'           => function () {
            $requestKey = Helper::INSTANT_CHECKOUT_URL_PARAM;
            $previousHash = App::request()->get($requestKey);
            try {
                $product = FcDomainFixture::product(['item_price' => 1001]);
                FcFixture::customer();
                $inertOrder = FcFixture::order([
                    'subtotal'     => 1001,
                    'total_amount' => 1001,
                ]);
                $coupon = FcFixture::coupon([
                    'status'     => 'active',
                    'type'       => 'fixed',
                    'amount'     => 2000,
                    'stackable'  => 'yes',
                    'conditions' => [
                        'min_purchase_amount' => 1001,
                        'max_discount_amount' => 1001,
                    ],
                ]);
                $line = [
                    'id'             => (int) $product['variation']->id,
                    'post_id'        => (int) $product['post']->ID,
                    'quantity'       => 1,
                    'unit_price'     => 1001,
                    'subtotal'       => 1001,
                    'discount_total' => 0,
                    'other_info'     => [],
                ];

                App::request()->set($requestKey, null);
                CartResource::resetCartCache();
                $service = new CouponServiceAdmin([$line]);
                $service->applyCoupon((string) $coupon->code);
                $calculated = $service->getCalculatedLineItems();
                $discount = $service->getDiscountData();

                FcTest::assertSame(
                    1001,
                    (int) $calculated[(int) $product['variation']->id]['coupon_discount'],
                    'line coupon cap exact cents'
                );
                FcTest::assertSame(
                    0,
                    (int) $calculated[(int) $product['variation']->id]['subtotal']
                        - (int) $calculated[(int) $product['variation']->id]['coupon_discount'],
                    'capped line total reaches zero without underflow'
                );
                FcTest::assertSame(1001, (int) $discount[$coupon->code]['discount'], 'discount ledger exact cents');

                $below = $line;
                $below['unit_price'] = 1000;
                $below['subtotal'] = 1000;
                $belowService = new CouponServiceAdmin([$below]);
                FcTest::assertSame(
                    false,
                    $belowService->ensureMinimumPurchaseAmount($coupon),
                    'one cent below minimum is rejected'
                );
                FcTest::assertSame(1001, (int) $inertOrder->total_amount, 'coupon inert Order canary');
                FcTest::assertSame(0, (int) $inertOrder->total_paid, 'coupon canary remains unpaid');
            } finally {
                App::request()->set($requestKey, $previousHash);
                CartResource::resetCartCache();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase23-coupon-multiline-cent-conservation',
        'name'          => 'Coupon multi-line distribution conserves every discount cent',
        'phase'         => 23,
        'known_failure' => true,
        'run'           => function () {
            $requestKey = Helper::INSTANT_CHECKOUT_URL_PARAM;
            $previousHash = App::request()->get($requestKey);
            try {
                $product = FcDomainFixture::product(['item_price' => 3333]);
                FcFixture::customer();
                $inertOrder = FcFixture::order([
                    'subtotal'     => 10000,
                    'total_amount' => 8999,
                ]);
                $coupon = FcFixture::coupon([
                    'status'     => 'active',
                    'type'       => 'fixed',
                    'amount'     => 1001,
                    'stackable'  => 'yes',
                    'conditions' => [],
                ]);
                $lines = [];
                foreach ([3333, 3333, 3334] as $index => $subtotal) {
                    $lines[] = [
                        'id'             => (int) $product['variation']->id + $index,
                        'post_id'        => (int) $product['post']->ID,
                        'quantity'       => 1,
                        'unit_price'     => $subtotal,
                        'subtotal'       => $subtotal,
                        'discount_total' => 0,
                        'other_info'     => [],
                    ];
                }

                App::request()->set($requestKey, null);
                CartResource::resetCartCache();
                $service = new CouponServiceAdmin($lines);
                $service->applyCoupon((string) $coupon->code);
                $calculated = $service->getCalculatedLineItems();
                $lineDiscount = array_sum(array_map(function ($line) {
                    return (int) $line['coupon_discount'];
                }, $calculated));

                if ($lineDiscount === 1001) {
                    FcTest::fail(
                        'KNOWN-FAILURE unexpectedly passed; remove FIX-PLAN #23 and make this a normal assertion.'
                    );
                } elseif ($lineDiscount === 1000) {
                    FcTest::skip(
                        'KNOWN-FAILURE — CanCalculateLineTotal rounds fractional cents then casts each '
                        . 'line to int; observed line discount sum 1000 versus coupon/order discount 1001.'
                    );
                } else {
                    FcTest::fail(
                        'Coupon conservation defect drifted: expected known 1000 cents, observed '
                        . $lineDiscount . '.'
                    );
                }
                FcTest::assertSame(0, (int) $inertOrder->total_paid, 'conservation canary remains unpaid');
            } finally {
                App::request()->set($requestKey, $previousHash);
                CartResource::resetCartCache();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase23-refund-stock-idempotent-cents',
        'name'          => 'Refund and UpdateStock apply one partial refund once in money and inventory',
        'phase'         => 23,
        'known_failure' => false,
        'run'           => function () {
            $mailFilter = function () {
                return false;
            };
            $stockHook = null;
            try {
                $product = FcDomainFixture::product([
                    'item_price'       => 3334,
                    'fulfillment_type' => 'physical',
                    'total_stock'      => 10,
                    'available'        => 10,
                    'committed'        => 0,
                    'on_hold'          => 0,
                ]);
                FcFixture::customer();
                $order = FcFixture::reportOrder([
                    'subtotal'        => 10001,
                    'total_amount'    => 10001,
                    'total_paid'      => 10001,
                    'payment_status'  => Status::PAYMENT_PAID,
                    'shipping_status' => Status::SHIPPING_UNSHIPPED,
                ]);
                $item = FcFixture::reportOrderItem((int) $order->id, [
                    'post_id'          => (int) $product['post']->ID,
                    'object_id'        => (int) $product['variation']->id,
                    'quantity'         => 3,
                    'unit_price'       => 3334,
                    'subtotal'         => 10002,
                    'line_total'       => 10002,
                    'fulfillment_type' => 'physical',
                ]);
                $order = FcFixture::reloadOrder((int) $order->id);
                $order->load('order_items');

                UpdateStock::handle((object) [
                    'hook'  => 'fluent_cart/order_created',
                    'order' => $order,
                ]);
                $variation = ProductVariation::query()->find((int) $product['variation']->id);
                FcTest::assertSame([7, 0, 3], [
                    (int) $variation->available,
                    (int) $variation->committed,
                    (int) $variation->on_hold,
                ], 'created Order reserves exactly three units');

                UpdateStock::handle((object) [
                    'hook'        => 'fluent_cart/order_status_updated',
                    'order'       => $order,
                    'oldStatus'   => Status::SHIPPING_UNSHIPPED,
                    'newStatus'   => Status::SHIPPING_SHIPPED,
                    'manageStock' => true,
                ]);
                $variation = ProductVariation::query()->find((int) $product['variation']->id);
                FcTest::assertSame([7, 3, 0], [
                    (int) $variation->available,
                    (int) $variation->committed,
                    (int) $variation->on_hold,
                ], 'shipped Order commits the same three units');

                $parent = OrderTransaction::query()->create([
                    'order_id'         => (int) $order->id,
                    'order_type'       => (string) $order->type,
                    'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                    'payment_method'   => '',
                    'payment_mode'     => 'test',
                    'payment_method_type' => '',
                    'status'           => Status::TRANSACTION_SUCCEEDED,
                    'currency'         => 'USD',
                    'total'            => 10001,
                    'rate'             => 1,
                    'meta'             => [],
                ]);

                $stockHook = function () use ($order, $item) {
                    $partialItem = clone $item;
                    $partialItem->quantity = 1;
                    $partialOrder = clone $order;
                    $partialOrder->setRelation('order_items', new Collection([$partialItem]));
                    UpdateStock::handle((object) [
                        'hook'        => 'fluent_cart/order_refunded',
                        'order'       => $partialOrder,
                        'manageStock' => true,
                    ]);
                };
                add_action('fluent_cart/order_refunded', $stockHook, 10, 1);
                add_filter('fluent_cart/should_send_email_notification', $mailFilter, 1, 2);

                $refundData = [
                    'vendor_charge_id' => 'phase23-refund-' . substr(hash('sha256', FcFixture::identity()), 0, 16),
                    'total'            => 1001,
                    'status'           => Status::TRANSACTION_REFUNDED,
                    'reason'           => 'Phase 23 partial exact-cent refund',
                ];
                $first = Refund::createOrRecordRefund($refundData, $parent);
                $afterFirst = ProductVariation::query()->find((int) $product['variation']->id);
                $second = Refund::createOrRecordRefund($refundData, $parent->fresh());
                $afterSecond = ProductVariation::query()->find((int) $product['variation']->id);
                $storedOrder = FcFixture::reloadOrder((int) $order->id);
                $storedParent = OrderTransaction::query()->find((int) $parent->id);
                $refundCount = OrderTransaction::query()
                    ->where('order_id', (int) $order->id)
                    ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
                    ->count();

                FcTest::assertSame((int) $first->id, (int) $second->id, 'duplicate provider refund returns original row');
                FcTest::assertSame(1, (int) $refundCount, 'duplicate refund creates no second transaction');
                FcTest::assertSame(1001, (int) $first->total, 'partial refund exact cents');
                FcTest::assertSame(1001, (int) $storedOrder->total_refund, 'Order refund exact cents');
                FcTest::assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, (string) $storedOrder->payment_status, 'partial refund status');
                FcTest::assertSame(1001, (int) ($storedParent->meta['refunded_total'] ?? 0), 'parent refunded total exact cents');
                FcTest::assertSame([8, 2, 0], [
                    (int) $afterFirst->available,
                    (int) $afterFirst->committed,
                    (int) $afterFirst->on_hold,
                ], 'partial refund restores exactly one unit');
                FcTest::assertSame([8, 2, 0], [
                    (int) $afterSecond->available,
                    (int) $afterSecond->committed,
                    (int) $afterSecond->on_hold,
                ], 'repeat refund is a stock no-op');
                FcTest::assertSame(0, count(FcTest::sentMails()), 'refund emits no mail in the inert test contract');
            } finally {
                if ($stockHook !== null) {
                    remove_action('fluent_cart/order_refunded', $stockHook, 10);
                }
                remove_filter('fluent_cart/should_send_email_notification', $mailFilter, 1);
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase23-checkout-api-exact-cents',
        'name'          => 'CheckoutApi passes exact tax cents into one inert pending Order',
        'phase'         => 23,
        'known_failure' => false,
        'run'           => function () use ($phase23CartItem) {
            $requestKey = Helper::INSTANT_CHECKOUT_URL_PARAM;
            $previousHash = App::request()->get($requestKey);
            $previousUserId = get_current_user_id();
            $gateway = new FcPhase23InertGateway();
            $slug = 'phase23_inert';
            $registeredManagers = [];
            $taxBehaviorFilter = function () {
                return 1;
            };
            $dieFilter = function () {
                return function () {
                    throw new RuntimeException('phase23-json-terminated');
                };
            };
            $cartHash = '';
            $capturedOutput = '';
            $terminated = false;

            try {
                $customer = FcFixture::customer();
                FcFixture::customerAddress((int) $customer->id, 'billing');
                FcFixture::customerAddress((int) $customer->id, 'shipping');

                $cart = FcAutomationFixture::cart('phase23-checkout', '2001-02-03 04:05:06', [
                    'customer_id'   => (int) $customer->id,
                    'cart_group'    => 'instant',
                    'stage'         => 'cart',
                    'cart_data'     => [
                        $phase23CartItem(230099, 1001, 1),
                    ],
                    'checkout_data' => [
                        'disable_coupons' => 'yes',
                        'fees'            => [],
                        'tax_data'        => [
                            'tax_total'          => 1,
                            'shipping_tax'       => 0,
                            'tax_behavior'       => 1,
                            'store_tax_behavior' => 1,
                            'exclusive_tax_total' => 1,
                            'fee_tax'            => 0,
                            'fee_tax_lines'      => [],
                        ],
                    ],
                ]);
                $cartHash = (string) $cart->cart_hash;

                foreach ([GatewayManager::getInstance(), App::gateway()] as $manager) {
                    if (!$manager || isset($registeredManagers[spl_object_hash($manager)])) {
                        continue;
                    }
                    FcTest::assertSame(null, $manager->get($slug), 'inert gateway slug starts unused');
                    $manager->register($slug, $gateway);
                    $registeredManagers[spl_object_hash($manager)] = $manager;
                }

                App::request()->set($requestKey, $cartHash);
                wp_set_current_user(0);
                CartResource::resetCartCache();
                add_filter('fluent_cart/cart/tax_behavior', $taxBehaviorFilter, PHP_INT_MAX, 2);
                if (!defined('DOING_AJAX')) {
                    define('DOING_AJAX', true);
                }
                add_filter('wp_die_ajax_handler', $dieFilter, PHP_INT_MAX);
                ob_start();
                try {
                    CheckoutApi::placeOrder([
                        'billing_email'      => FcFixture::identity(),
                        'billing_full_name'  => 'Phase Twenty Three',
                        'billing_first_name' => 'Phase',
                        'billing_last_name'  => 'Twenty Three',
                        'billing_country'    => 'BD',
                        'billing_state'      => 'BD-13',
                        'billing_city'       => 'Dhaka',
                        'billing_postcode'   => '1205',
                        'billing_address_1'  => 'Phase 23 exact checkout',
                        'billing_address_2'  => '',
                        'billing_phone'      => '',
                        'ship_to_different'  => 'no',
                        'agree_terms'        => 'yes',
                        '_fct_pay_method'    => $slug,
                        'order_notes'        => FcFixture::orderMarker(),
                        'user_tz'            => 'UTC',
                    ], true);
                } catch (RuntimeException $e) {
                    if ($e->getMessage() !== 'phase23-json-terminated') {
                        throw $e;
                    }
                    $terminated = true;
                } finally {
                    $capturedOutput = ob_get_clean();
                    remove_filter('wp_die_ajax_handler', $dieFilter, PHP_INT_MAX);
                }

                $storedCart = FcAutomationFixture::findCart($cartHash);
                $payload = json_decode($capturedOutput, true);
                if ($storedCart === null || (int) $storedCart->order_id <= 0) {
                    FcTest::fail(
                        'checkout linked one Order; response=' . wp_json_encode($payload)
                    );
                    return;
                }
                $order = FcFixture::captureCheckoutOrder((int) $storedCart->order_id);

                FcTest::assertSame(true, $terminated, 'CheckoutApi JSON boundary was caught');
                FcTest::assertSame('success', (string) ($payload['status'] ?? ''), 'inert gateway response');
                FcTest::assertSame(1002, (int) ($payload['amount'] ?? 0), 'response payable exact cents');
                FcTest::assertSame([1002], $gateway->amounts, 'gateway observed one exact amount');
                FcTest::assertSame(1001, (int) $order->subtotal, 'CheckoutApi order subtotal');
                FcTest::assertSame(1, (int) $order->tax_total, 'CheckoutApi exclusive tax cent');
                FcTest::assertSame(1002, (int) $order->total_amount, 'CheckoutApi order payable cents');
                FcTest::assertSame(Status::PAYMENT_PENDING, (string) $order->payment_status, 'CheckoutApi order remains pending');
                FcTest::assertSame(0, (int) $order->total_paid, 'CheckoutApi completes no payment');
                FcTest::assertSame([], FcTest::externalCalls(), 'CheckoutApi inert path has no outbound HTTP');
            } finally {
                if ($cartHash !== '') {
                    global $wpdb;
                    $lockName = 'fct_checkout_' . md5($wpdb->prefix . $cartHash);
                    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
                    $storedCart = FcAutomationFixture::findCart($cartHash);
                    if ($storedCart && (int) $storedCart->order_id > 0) {
                        FcFixture::captureCheckoutOrder((int) $storedCart->order_id);
                    }
                }
                foreach ($registeredManagers as $manager) {
                    $manager->remove($slug);
                }
                remove_filter('fluent_cart/cart/tax_behavior', $taxBehaviorFilter, PHP_INT_MAX);
                wp_set_current_user($previousUserId);
                App::request()->set($requestKey, $previousHash);
                CartResource::resetCartCache();
                FcDomainFixture::cleanupAll();
                FcAutomationFixture::cleanupAll();
            }
        },
    ],
];
