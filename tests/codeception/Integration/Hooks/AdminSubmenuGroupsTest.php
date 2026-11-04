<?php

namespace Tests\Integration\Hooks;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Hooks\Handlers\MenuHandler;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The FluentCart sidebar is split into groups, and the first row of each
 * group after the first carries a class that draws the divider.
 */
class AdminSubmenuGroupsTest extends WPTestCase
{
    private const GROUP_START = 'fct_menu_group_start';

    protected function setUp(): void
    {
        parent::setUp();
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function row($key, $capability = 'manage_options')
    {
        return [ucfirst($key), $capability, 'admin.php?page=fluent-cart#/' . $key, '', 'fluent_cart_' . $key];
    }

    private function taxonomyRow($taxonomy)
    {
        return [$taxonomy, 'manage_options', 'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . FluentProducts::CPT_NAME, '', $taxonomy];
    }

    private function groupStarts(array $items)
    {
        $starts = [];
        foreach ($items as $key => $item) {
            if (strpos((string)($item[4] ?? ''), self::GROUP_START) !== false) {
                $starts[] = $key;
            }
        }
        return $starts;
    }

    public function test_rows_are_ordered_by_group(): void
    {
        $items = [];
        foreach (['dashboard', 'orders', 'customers', 'products', 'subscriptions', 'reports', 'integrations', 'settings', 'coupons'] as $key) {
            $items[$key] = $this->row($key);
        }
        $items['product-categories'] = $this->taxonomyRow('product-categories');
        $items['migrator'] = $this->row('migrator');

        $grouped = (new MenuHandler())->groupSubmenu($items);

        $this->assertSame([
            'dashboard', 'reports',
            'orders', 'customers', 'subscriptions',
            'products', 'product-categories', 'coupons',
            'integrations', 'settings',
            'migrator',
        ], array_keys($grouped));

        $this->assertSame(['orders', 'products', 'integrations', 'migrator'], $this->groupStarts($grouped));
        $this->assertSame('fluent_cart_orders ' . self::GROUP_START, $grouped['orders'][4], 'the original class is kept');
    }

    public function test_pages_reachable_from_the_top_bar_are_left_out(): void
    {
        $items = ['products' => $this->row('products')];
        foreach (['attributes', 'inventory', 'reviews', 'product_feed', 'taxes', 'logs'] as $key) {
            $items[$key] = $this->row($key);
        }

        $grouped = (new MenuHandler())->groupSubmenu($items);

        $this->assertSame(['products'], array_keys($grouped));
    }

    /**
     * The hidden rows are only redundant next to the top bar. A site that
     * turns it off keeps them in the sidebar, their only way in.
     */
    public function test_rows_stay_when_the_top_bar_is_turned_off(): void
    {
        $items = ['products' => $this->row('products')];
        foreach (['attributes', 'inventory', 'reviews', 'product_feed', 'taxes', 'logs'] as $key) {
            $items[$key] = $this->row($key);
        }

        add_filter('fluent_cart/show_admin_top_bar', '__return_false');
        $grouped = (new MenuHandler())->groupSubmenu($items);
        remove_filter('fluent_cart/show_admin_top_bar', '__return_false');

        $this->assertEqualsCanonicalizing(array_keys($items), array_keys($grouped));
    }

    public function test_hidden_rows_can_be_shown_again(): void
    {
        $filter = function ($hidden) {
            return array_values(array_diff($hidden, ['inventory', 'logs']));
        };
        add_filter('fluent_cart/admin_menu/hidden_submenu_items', $filter);

        $inventory = $this->row('inventory');
        $inventory[0] = '↳ Inventory'; // label Pro still sends

        $grouped = (new MenuHandler())->groupSubmenu([
            'products'  => $this->row('products'),
            'inventory' => $inventory,
            'logs'      => $this->row('logs'),
        ]);

        remove_filter('fluent_cart/admin_menu/hidden_submenu_items', $filter);

        $this->assertSame(['products', 'inventory', 'logs'], array_keys($grouped));
        $this->assertSame('Inventory', $grouped['inventory'][0], 'a shown child loses the arrow');
        $this->assertStringContainsString('fct_menu_child', $grouped['inventory'][4]);
        $this->assertStringNotContainsString('fct_menu_child', $grouped['products'][4]);
    }

    public function test_hidden_first_row_moves_the_divider_to_the_next_visible_row(): void
    {
        $items = [
            'dashboard' => $this->row('dashboard'),
            'orders'    => $this->row('orders', 'do_not_allow'),
            'customers' => $this->row('customers'),
        ];

        $grouped = (new MenuHandler())->groupSubmenu($items);

        $this->assertSame(['customers'], $this->groupStarts($grouped));
    }

    public function test_unknown_rows_sit_in_their_own_group_before_tools(): void
    {
        $items = [
            'dashboard' => $this->row('dashboard'),
            'migrator'  => $this->row('migrator'),
            'shipping'  => $this->row('shipping'),
            7           => ['Add-on page', 'manage_options', 'fluent-cart-addon'],
        ];

        $grouped = (new MenuHandler())->groupSubmenu($items);

        $this->assertSame(['dashboard', 'shipping', 7, 'migrator'], array_keys($grouped));
        $this->assertSame(['shipping', 'migrator'], $this->groupStarts($grouped));
    }

    public function test_groups_are_filterable(): void
    {
        $filter = function ($groups) {
            $groups['catalog'][] = 'shipping';
            return $groups;
        };
        add_filter('fluent_cart/admin_menu/submenu_groups', $filter);

        $grouped = (new MenuHandler())->groupSubmenu([
            'products' => $this->row('products'),
            'shipping' => $this->row('shipping'),
            'migrator' => $this->row('migrator'),
        ]);

        remove_filter('fluent_cart/admin_menu/submenu_groups', $filter);

        $this->assertSame(['products', 'shipping', 'migrator'], array_keys($grouped));
        $this->assertSame(['migrator'], $this->groupStarts($grouped));
    }

    public function test_the_real_menu_keeps_custom_taxonomies_with_products(): void
    {
        global $submenu;
        $submenu = [];

        register_taxonomy('fct_test_cuisine', FluentProducts::CPT_NAME, ['public' => true, 'label' => 'Cuisines']);

        $handler = new MenuHandler();
        $handler->addAdminMenu();
        $handler->groupAdminSubmenu();

        unregister_taxonomy('fct_test_cuisine');

        $keys = array_keys($submenu['fluent-cart']);
        $productsAt = array_search('products', $keys, true);
        $taxonomyAt = array_search('fct_test_cuisine', $keys, true);

        $this->assertNotFalse($taxonomyAt);
        $this->assertGreaterThan($productsAt, $taxonomyAt);
        foreach (array_slice($keys, $productsAt + 1, $taxonomyAt - $productsAt) as $between) {
            $this->assertStringNotContainsString(self::GROUP_START, (string)($submenu['fluent-cart'][$between][4] ?? ''), $between . ' must stay in the products group');
        }
        $this->assertSame('coupons', $keys[$taxonomyAt + 1] ?? null, 'taxonomies sit between Products and Coupons');
        $this->assertArrayNotHasKey('logs', $submenu['fluent-cart']);
    }
}
