<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\AdminHelper;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\UserRoleFactory;

/**
 * admin_menu.php renders a dropdown's children only when its parent passes,
 * so the Products parent has to accept every permission one of its children
 * is gated on. A role holding just one of them (reviews/manage, or
 * products/create / products/edit for the taxonomy rows) would otherwise lose
 * links it is allowed to use.
 */
class ProductsMenuParentGateTest extends WPTestCase
{
    private function productsMenu(): array
    {
        $method = new \ReflectionMethod(AdminHelper::class, 'getProductsMenu');
        $method->setAccessible(true);

        return $method->invoke(null, 'admin.php?page=fluent-cart#/');
    }

    public function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(UserRoleFactory::createAdmin());
    }

    public function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_the_parent_accepts_every_child_permission(): void
    {
        $menu = $this->productsMenu();
        $parent = (array)$menu['permission'];

        foreach ($menu['children'] as $slug => $child) {
            foreach ((array)($child['permission'] ?? []) as $permission) {
                $this->assertContains($permission, $parent, "the parent hides child '{$slug}' from roles holding only {$permission}");
            }
        }
    }

    /**
     * The taxonomy rows carry no permission of their own — getProductsMenu()
     * adds them only for products/create or products/edit — so the parent has
     * to name those two explicitly.
     */
    public function test_the_parent_accepts_the_taxonomy_editing_permissions(): void
    {
        $parent = (array)$this->productsMenu()['permission'];

        $this->assertContains('products/create', $parent);
        $this->assertContains('products/edit', $parent);
    }
}
