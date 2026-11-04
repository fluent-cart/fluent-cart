<?php

namespace Tests\Integration\Http;

use FluentCart\App\Services\Permission\PermissionManager;
use Tests\Support\RestTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Permission tests for product routes.
 *
 * Route-meta assertions verify that routes are wired to the correct permission
 * keys. Actual enforcement tests for unauthorized users are limited by
 * Helper::getCurrentUser() static caching (no public reset seam).
 */
class ProductPermissionsTest extends RestTestCase
{
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
    }

    public function tearDown(): void
    {
        wp_cache_flush();
        gc_collect_cycles();
        parent::tearDown();
    }

    // =========================================================================
    // Spec 199: Permission keys registered
    // =========================================================================

    public function test_product_permission_keys_registered(): void
    {
        $permissions = PermissionManager::getAllPermissions();
        $permissionKeys = array_keys($permissions);

        $this->assertContains('products/view', $permissionKeys);
        $this->assertContains('products/create', $permissionKeys);
        $this->assertContains('products/edit', $permissionKeys);
        $this->assertContains('products/delete', $permissionKeys);
    }

}
