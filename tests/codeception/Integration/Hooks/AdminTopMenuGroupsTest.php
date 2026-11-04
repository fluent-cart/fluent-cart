<?php

namespace Tests\Integration\Hooks;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\AdminHelper;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The in-app top bar groups its dropdowns: product taxonomies sit under
 * Products, and "More" is ordered into groups with a divider between them.
 */
class AdminTopMenuGroupsTest extends WPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        register_taxonomy('fct_test_cuisine', FluentProducts::CPT_NAME, ['public' => true, 'label' => 'Cuisines']);
    }

    protected function tearDown(): void
    {
        unregister_taxonomy('fct_test_cuisine');
        parent::tearDown();
    }

    public function test_product_taxonomies_are_grouped_under_products(): void
    {
        $menu = AdminHelper::getMenuItems();
        $products = $menu['products']['children'];
        $more = $menu['more']['children'];

        $this->assertArrayHasKey('fct_test_cuisine', $products, 'every public product taxonomy is linked');
        $this->assertSame('taxonomies', $products['fct_test_cuisine']['group']);
        $this->assertSame('products', $products['all_products']['group']);
        $this->assertArrayNotHasKey('categories', $more);
        $this->assertArrayNotHasKey('brands', $more);

        $groups = array_column($products, 'group');
        $firstTaxonomy = array_search('taxonomies', $groups, true);
        $this->assertNotContains('products', array_slice($groups, $firstTaxonomy), 'product pages come before taxonomies');
    }

    public function test_more_items_are_ordered_by_group(): void
    {
        $addon = function ($items) {
            $items['withdrawal_requests'] = ['label' => 'Withdrawals', 'link' => '#'];
            $items['some_addon'] = ['label' => 'Some Add-on', 'link' => '#'];
            // Pro inserts Order Bump ahead of Coupons.
            return ['order_bump' => ['label' => 'Order Bump', 'link' => '#']] + $items;
        };
        add_filter('fluent_cart/global_admin_menu_more_items', $addon);

        $more = AdminHelper::getMenuItems()['more']['children'];

        remove_filter('fluent_cart/global_admin_menu_more_items', $addon);

        $keys = array_keys($more);
        $this->assertLessThan(array_search('integrations', $keys, true), array_search('coupons', $keys, true));
        $this->assertSame(['coupons', 'order_bump'], array_slice($keys, 0, 2), 'items follow the order their group lists them in');
        $this->assertSame(['integrations', 'withdrawal_requests', 'logs', 'some_addon'], array_slice($keys, -4));
        $this->assertSame('marketing', $more['coupons']['group']);
        $this->assertSame(['store'], array_values(array_unique(array_column(array_slice($more, -4), 'group'))), 'two groups only; unclaimed items join Store');
    }

    public function test_the_top_bar_draws_a_divider_where_the_group_changes(): void
    {
        $html = (string)AdminHelper::getAdminMenu();

        preg_match_all('/fct_menu_child_item_([a-z0-9_-]+)( fct_menu_child_item_group_start)?"/', $html, $matches, PREG_SET_ORDER);
        $starts = [];
        foreach ($matches as $match) {
            if (!empty($match[2])) {
                $starts[] = $match[1];
            }
        }

        $this->assertContains('product-categories', $starts, 'the first taxonomy opens its group');
        $more = AdminHelper::getMenuItems()['more']['children'];
        $this->assertContains(isset($more['taxes']) ? 'taxes' : 'integrations', $starts, 'the Store group opens with a divider');
        $this->assertNotContains('logs', $starts, 'More has a single divider');
        $this->assertNotContains('withdrawal_requests', $starts);
        $this->assertNotContains('all_products', $starts, 'the first item of a dropdown has no divider');
        $this->assertNotContains('coupons', $starts);
    }
}
