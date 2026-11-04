<?php

namespace Tests\Integration\Modules\Shipping;

use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsShippingClassCarts;

/**
 * Shipping class surcharges are grouped by class, then aggregated across classes.
 */
class ShippingClassChargeTest extends DatabaseTestCase
{
    use BuildsShippingClassCarts;

    public function ratePaths(): array
    {
        return [
            'zone'    => ['zone'],
            'profile' => ['profile'],
        ];
    }

    /**
     * @dataProvider ratePaths
     */
    public function test_a_class_without_per_item_charges_once_across_lines(string $path): void
    {
        $bulky = $this->shippingClass('fixed', 5, false);
        $methodId = $this->perOrderMethod(10, 'sum_all');

        $result = $this->classShipping($path, $methodId, [$this->classLine($bulky, 2), $this->classLine($bulky, 1)]);

        $this->assertSame(1500, (int) $result['shipping_amount'], '$10 base + $5 once for the Bulky class');
        $this->assertSame(1500, $this->itemizedShippingSum($result['items']));
    }

    /**
     * @dataProvider ratePaths
     */
    public function test_a_per_item_class_charges_every_unit_across_lines(string $path): void
    {
        $fragile = $this->shippingClass('fixed', 5, true);
        $methodId = $this->perOrderMethod(10, 'sum_all');

        $result = $this->classShipping($path, $methodId, [$this->classLine($fragile, 2), $this->classLine($fragile, 1)]);

        $this->assertSame(2500, (int) $result['shipping_amount'], '$10 base + $5 x 3 units');
        $this->assertSame(2500, $this->itemizedShippingSum($result['items']));
    }

    /**
     * @dataProvider ratePaths
     */
    public function test_highest_class_takes_the_class_total_not_the_largest_line(string $path): void
    {
        $fragile = $this->shippingClass('fixed', 5, true);
        $methodId = $this->perOrderMethod(10, 'highest_class');

        $result = $this->classShipping($path, $methodId, [$this->classLine($fragile, 2), $this->classLine($fragile, 1)]);

        $this->assertSame(2500, (int) $result['shipping_amount'], '$10 base + Fragile class $15');
        $this->assertSame(2500, $this->itemizedShippingSum($result['items']));
    }

    /**
     * @dataProvider ratePaths
     */
    public function test_highest_class_picks_the_largest_class_charge(string $path): void
    {
        $small = $this->shippingClass('fixed', 3, true);
        $large = $this->shippingClass('fixed', 7, false);
        $methodId = $this->perOrderMethod(10, 'highest_class');

        // Small is $3 x 3 = $9 over two lines, Large is $7 on one: Small wins, though Large has the biggest line.
        $result = $this->classShipping($path, $methodId, [
            $this->classLine($small, 2),
            $this->classLine($small, 1),
            $this->classLine($large, 1),
        ]);

        $this->assertSame(1900, (int) $result['shipping_amount']);
        $this->assertSame(1900, $this->itemizedShippingSum($result['items']));
    }

    /**
     * @dataProvider ratePaths
     */
    public function test_line_charges_add_up_to_the_total_with_uneven_line_totals(string $path): void
    {
        $once = $this->shippingClass('fixed', 4, false);
        $each = $this->shippingClass('fixed', 1.5, true);
        $methodId = $this->perOrderMethod(10, 'highest_class');

        $result = $this->classShipping($path, $methodId, [
            $this->classLine($once, 1, 333),
            $this->classLine($once, 2, 777),
            $this->classLine($each, 3, 1111),
        ]);

        $this->assertSame(1450, (int) $result['shipping_amount'], '$10 base + per-item class $1.50 x 3');
        $this->assertSame(1450, $this->itemizedShippingSum($result['items']));
    }

    /**
     * @dataProvider ratePaths
     */
    public function test_a_per_item_percentage_class_sums_its_lines(string $path): void
    {
        $percent = $this->shippingClass('percentage', 10, true);
        $methodId = $this->perOrderMethod(10, 'sum_all');

        // 10% x $10 x 2 + 10% x $20 x 1.
        $result = $this->classShipping($path, $methodId, [
            $this->classLine($percent, 2, 1000),
            $this->classLine($percent, 1, 2000),
        ]);

        $this->assertSame(1400, (int) $result['shipping_amount']);
        $this->assertSame(1400, $this->itemizedShippingSum($result['items']));
    }

    /**
     * @dataProvider ratePaths
     */
    public function test_a_percentage_class_without_per_item_charges_once_on_its_highest_price(string $path): void
    {
        $percent = $this->shippingClass('percentage', 10, false);
        $methodId = $this->perOrderMethod(10, 'sum_all');

        // 10% of the $30 unit, once; per line it was 10% x $10 + 10% x $30.
        $result = $this->classShipping($path, $methodId, [
            $this->classLine($percent, 2, 1000),
            $this->classLine($percent, 1, 3000),
        ]);

        $this->assertSame(1300, (int) $result['shipping_amount'], '$10 base + 10% of the highest unit price once');
        $this->assertSame(1300, $this->itemizedShippingSum($result['items']));
    }

    /**
     * @dataProvider ratePaths
     */
    public function test_the_percentage_basis_filter_can_charge_each_line(string $path): void
    {
        $percent = $this->shippingClass('percentage', 10, false);
        $methodId = $this->perOrderMethod(10, 'sum_all');
        $basis = function () {
            return 'per_line';
        };
        add_filter('fluent_cart/shipping/percentage_class_basis', $basis);

        $result = $this->classShipping($path, $methodId, [
            $this->classLine($percent, 2, 1000),
            $this->classLine($percent, 1, 3000),
        ]);
        remove_filter('fluent_cart/shipping/percentage_class_basis', $basis);

        $this->assertSame(1400, (int) $result['shipping_amount'], '$10 base + 10% of each line unit price');
        $this->assertSame(1400, $this->itemizedShippingSum($result['items']));
    }
}
