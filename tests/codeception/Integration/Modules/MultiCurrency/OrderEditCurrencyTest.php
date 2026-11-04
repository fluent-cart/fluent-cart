<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\Resource\OrderItemResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\PriceConverter;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * A product added to an existing order in the admin edit is priced in the
 * order's currency at the order's own frozen rate and rules
 * (dev-docs/multi-currency/plan.md §9.1). The picker converts the base price.
 * Base USD; the order is GBP at 0.79.
 */
class OrderEditCurrencyTest extends DatabaseTestCase
{
    public function test_an_added_product_is_priced_at_the_orders_own_rate(): void
    {
        [$order, $existing] = $this->order('GBP', '0.79');
        $variation = $this->variation(4980);

        $this->edit($order, $existing, $variation);

        $added = OrderItem::query()->where('order_id', $order->id)->where('object_id', $variation->id)->first();
        $this->assertNotNull($added);
        $this->assertSame(3934, (int) $added->unit_price, '$49.80 × 0.79 = £39.34, not £49.80');
        $this->assertSame(3934, (int) $added->subtotal);

        $kept = OrderItem::query()->find($existing->id);
        $this->assertSame((int) $existing->unit_price, (int) $kept->unit_price, 'an item already on the order keeps its price');
        $this->assertSame('GBP', Order::query()->find($order->id)->currency);
    }

    public function test_the_orders_recorded_pricing_rules_apply_too(): void
    {
        [$order, $existing] = $this->order('GBP', '0.79', ['rounding' => 'up_1', 'charm' => 'minus_0_01']);
        $variation = $this->variation(4980);

        $this->edit($order, $existing, $variation);

        $added = OrderItem::query()->where('order_id', $order->id)->where('object_id', $variation->id)->first();
        $this->assertSame(3999, (int) $added->unit_price, '£39.34 → up to £40 → £39.99, the rules the order was sold under');
    }

    public function test_a_base_currency_order_is_unchanged(): void
    {
        [$order, $existing] = $this->order('USD', '1');
        $variation = $this->variation(4980);

        $this->edit($order, $existing, $variation);

        $added = OrderItem::query()->where('order_id', $order->id)->where('object_id', $variation->id)->first();
        $this->assertSame(4980, (int) $added->unit_price);
    }

    public function test_an_added_product_at_another_price_is_rejected_without_changes(): void
    {
        [$order, $existing] = $this->order('GBP', '0.79');
        $variation = $this->variation(4980);

        $result = OrderResource::update($this->editPayload($existing, $variation, 4980), $order->id);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('item_price_changed', $result->get_error_code());
        $this->assertNull(OrderItem::query()->where('order_id', $order->id)->where('object_id', $variation->id)->first());
    }

    public function test_added_products_load_in_one_batch_and_each_price_is_checked(): void
    {
        [$order, $existing] = $this->order('GBP', '0.79');
        $first = $this->variation(4980);
        $second = $this->variation(2000);
        $items = $this->editPayload($existing, $first, 3934)['orderData']['order_items'];
        $items[] = array_merge($items[1], ['object_id' => $second->id, 'post_id' => $second->post_id, 'unit_price' => 2000]);

        global $wpdb;
        $loads = 0;
        $count = function ($sql) use ($wpdb, &$loads) {
            if (preg_match('/^select (?!.* as aggregate ).*? from `' . $wpdb->prefix . 'fct_product_variations`/i', $sql)) {
                $loads++;
            }
            return $sql;
        };
        add_filter('query', $count);
        $matches = OrderItemResource::newItemPricesMatch($order, $items);
        remove_filter('query', $count);

        $this->assertFalse($matches, 'the second added product is still checked');
        $this->assertSame(1, $loads, 'two added products, one variation query');

        $items[2]['unit_price'] = 1580;
        $this->assertTrue(OrderItemResource::newItemPricesMatch($order, $items));
    }

    private function order(string $currency, string $rate, array $rules = []): array
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'currency'    => $currency,
            'rate'        => $rate,
            'config'      => ['fx' => CurrencySnapshot::make($currency, $rate, array_merge(['source' => CurrencySnapshot::SOURCE_MANUAL], $rules))],
        ]);
        $existing = (new OrderItemFactory())->create($order->id, ['unit_price' => 2000, 'subtotal' => 2000, 'line_total' => 2000]);

        return [Order::query()->find($order->id), $existing];
    }

    private function variation(int $basePrice): ProductVariation
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => $basePrice])->create();

        return ProductVariation::query()->find($product->variants->first()->id);
    }

    /**
     * What the edit screen sends: the existing line plus the picked product
     * at the order's rate (AddProductItemModal).
     */
    private function edit(Order $order, OrderItem $existing, ProductVariation $variation): void
    {
        $price = PriceConverter::toPrice($variation->item_price, CurrencySnapshot::fromOrder($order));
        $result = OrderResource::update($this->editPayload($existing, $variation, $price), $order->id);

        $this->assertNotInstanceOf(\WP_Error::class, $result);
    }

    private function editPayload(OrderItem $existing, ProductVariation $variation, int $price): array
    {
        return [
            'orderData'         => [
                'order_items' => [
                    [
                        'id'         => $existing->id,
                        'post_id'    => $existing->post_id,
                        'object_id'  => $existing->object_id,
                        'title'      => $existing->title,
                        'post_title' => $existing->post_title,
                        'quantity'   => 1,
                        'unit_price' => $existing->unit_price,
                    ],
                    [
                        'post_id'      => $variation->post_id,
                        'object_id'    => $variation->id,
                        'title'        => $variation->variation_title,
                        'post_title'   => 'Added',
                        'quantity'     => 1,
                        'unit_price'   => $price,
                        'payment_type' => 'onetime',
                        'other_info'   => ['payment_type' => 'onetime'],
                    ],
                ],
            ],
            'deletedItems'      => [],
            'discount'          => '',
            'shipping'          => '',
            'couponCalculation' => [],
        ];
    }
}
