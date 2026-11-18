<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Modules\Templating\Bricks\Elements\ProductsCollection;
use FluentCart\App\Services\Filter\ShopAttributeFilter;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The Bricks Products element's attribute filter controls.
 */
class BricksProductsAttributeFilterTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/Support/Stubs/BricksElementStubs.php';
        AttributeGroup::query()->create(['title' => 'Test Size', 'slug' => 'test-size-' . wp_generate_password(6, false)]);
    }

    public function test_the_attribute_picker_states_the_storefronts_group_limit(): void
    {
        $element = new ProductsCollection();
        $element->set_controls();

        $attributePicker = $element->controls['attributeFilterGroups'] ?? [];
        $this->assertTrue(!empty($attributePicker['multiple']), 'the picker takes several groups');
        $this->assertStringContainsString((string) ShopAttributeFilter::MAX_GROUPS, $attributePicker['description'] ?? '',
            'groups past the limit are dropped on the storefront, so the picker says how many show');
    }
}
