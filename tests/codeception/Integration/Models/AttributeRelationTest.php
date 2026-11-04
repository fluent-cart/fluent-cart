<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\AttributeRelation;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * fct_atts_relations.object_id holds a VARIATION id. Detail ids and variation
 * ids live in different tables, so on a busy store the same number exists in
 * both — every relation must resolve through the variation, never by
 * treating object_id as a detail id.
 */
class AttributeRelationTest extends WPTestCase
{
    public function test_a_term_link_resolves_its_own_variation_and_product_when_ids_collide(): void
    {
        global $wpdb;
        $productFactory = new ProductFactory();

        $owner   = $productFactory->create();
        $variant = $owner->variants->first();

        $collisionId = 1000 + max(
            (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}fct_product_details"),
            (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}fct_product_variations")
        );
        $wpdb->update($wpdb->prefix . 'fct_product_variations', ['id' => $collisionId], ['id' => $variant->id]);

        // An unrelated product whose detail id equals the variation id.
        $unrelated = $productFactory->create();
        $wpdb->update($wpdb->prefix . 'fct_product_details', ['id' => $collisionId], ['post_id' => $unrelated->ID]);

        $link = AttributeRelation::query()->create([
            'group_id'  => 1,
            'term_id'   => 1,
            'object_id' => $collisionId,
        ]);
        $link = AttributeRelation::query()->find($link->id);

        $this->assertNotNull($link->variation, 'The link should resolve its variation');
        $this->assertSame($collisionId, (int) $link->variation->id, 'The link should resolve the variation stored in object_id');
        $this->assertSame((int) $owner->ID, (int) $link->variation->post_id, 'The variation should belong to the owning product');

        $detail = $link->productDetails;

        $this->assertNotNull($detail, 'productDetails should resolve a detail');
        $this->assertSame((int) $owner->ID, (int) $detail->post_id, 'productDetails must return the owning product detail, not the one whose id collides');
    }
}
