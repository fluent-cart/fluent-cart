<?php

namespace Tests\Integration\Http;

use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Modules\FluentPlayer\ProductVideoSettings;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\RestTestCase;
use Tests\Support\Traits\BuildsProductUpdatePayloads;

/**
 * The FluentPlayer product video config travels through the normal product
 * update route (POST /fluent-cart/v2/products/{postId}/pricing) as
 * detail.other_info.fluent_player_video. These tests pin the request
 * sanitizer + the other_info merge in ProductDetailResource::update().
 */
class ProductVideoUpdateTest extends RestTestCase
{
    use BuildsProductUpdatePayloads;

    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
    }

    public function tearDown(): void
    {
        wp_cache_flush();
        parent::tearDown();
    }

    private function createProductWithOtherInfo(array $otherInfo): Product
    {
        return $this->productFactory
            ->withDetailAttributes(['other_info' => $otherInfo])
            ->create();
    }

    private function updateProduct(Product $product, array $overrides): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params($this->buildUpdatePayload($product, $overrides));

        return $this->dispatchRequestSilently($request);
    }

    private function freshOtherInfo(Product $product): array
    {
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();

        return is_array($detail->other_info) ? $detail->other_info : [];
    }

    public function test_video_config_is_persisted_and_sibling_keys_survive(): void
    {
        $product = $this->createProductWithOtherInfo(['reviews_enabled' => 'yes']);

        // The sibling key is left out of the payload on purpose: the merge in
        // ProductDetailResource::update() must keep it, not the request echo.
        $payload = $this->buildUpdatePayload($product, [
            'detail' => ['other_info' => ['fluent_player_video' => ['media_ids' => ['77', '78', '77'], 'display' => 'modal']]],
        ]);
        unset($payload['detail']['other_info']['reviews_enabled']);
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params($payload);
        $response = $this->dispatchRequestSilently($request);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));

        $otherInfo = $this->freshOtherInfo($product);
        $this->assertSame(['media_ids' => [77, 78], 'positions' => [null, null]], $otherInfo['fluent_player_video'], 'only the ordered unique id list is stored; a legacy display key is dropped');
        $this->assertSame('yes', $otherInfo['reviews_enabled'], 'other_info merge must keep unrelated keys');

        $config = ProductVideoSettings::fromProduct(Product::query()->find($product->ID));
        $this->assertSame(['media_ids' => [77, 78], 'positions' => [null, null]], $config);
    }

    public function test_invalid_values_are_coerced_before_storage(): void
    {
        $product = $this->createProductWithOtherInfo([]);

        $response = $this->updateProduct($product, [
            'detail' => ['other_info' => ['fluent_player_video' => ['media_ids' => ['abc', 0], 'extra' => 1]]],
        ]);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));

        $otherInfo = $this->freshOtherInfo($product);
        $this->assertSame(['media_ids' => [], 'positions' => []], $otherInfo['fluent_player_video']);
        $this->assertNull(ProductVideoSettings::fromProduct(Product::query()->find($product->ID)), 'an empty list reads as no video');
    }

    public function test_update_without_the_key_keeps_the_existing_video(): void
    {
        $product = $this->createProductWithOtherInfo([
            'fluent_player_video' => ['media_ids' => [5, 6]],
        ]);

        $payload = $this->buildUpdatePayload($product, ['post_title' => 'Renamed']);
        unset($payload['detail']['other_info']['fluent_player_video']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params($payload);
        $response = $this->dispatchRequestSilently($request);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(['media_ids' => [5, 6]], $this->freshOtherInfo($product)['fluent_player_video'], 'the stored value is left exactly as it was, positions included');
    }

    public function test_legacy_single_media_id_payload_is_upgraded_to_a_list(): void
    {
        $product = $this->createProductWithOtherInfo([]);

        $response = $this->updateProduct($product, [
            'detail' => ['other_info' => ['fluent_player_video' => ['media_id' => '9']]],
        ]);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(['media_ids' => [9], 'positions' => [null]], $this->freshOtherInfo($product)['fluent_player_video']);
    }

    public function test_comma_separated_ids_are_stored_as_a_list(): void
    {
        $product = $this->createProductWithOtherInfo([]);

        $response = $this->updateProduct($product, [
            'detail' => ['other_info' => ['fluent_player_video' => ['media_ids' => '21,22,21']]],
        ]);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(['media_ids' => [21, 22], 'positions' => [null, null]], $this->freshOtherInfo($product)['fluent_player_video']);
    }

    public function test_sending_an_empty_list_removes_the_videos(): void
    {
        $product = $this->createProductWithOtherInfo([
            'fluent_player_video' => ['media_ids' => [5, 6]],
        ]);

        // The admin sends the list as a string: an empty JSON array does not
        // survive the update request, so the removal would never arrive.
        $response = $this->updateProduct($product, [
            'detail' => ['other_info' => ['fluent_player_video' => ['media_ids' => '']]],
        ]);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertNull(ProductVideoSettings::fromProduct(Product::query()->find($product->ID)));
    }

    public function test_gallery_positions_are_stored_with_the_ids(): void
    {
        $product = $this->createProductWithOtherInfo([]);

        // The gallery dialog sends both lists as strings, with an empty slot
        // for a video that has no place of its own yet.
        $response = $this->updateProduct($product, [
            'detail' => ['other_info' => ['fluent_player_video' => ['media_ids' => '31,32', 'positions' => '0,']]],
        ]);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(
            ['media_ids' => [31, 32], 'positions' => [0, null]],
            $this->freshOtherInfo($product)['fluent_player_video']
        );
    }

    public function test_the_list_is_capped_before_it_is_stored(): void
    {
        $product = $this->createProductWithOtherInfo([]);
        $ids = range(101, 100 + ProductVideoSettings::MAX_VIDEOS + 5);

        $response = $this->updateProduct($product, [
            'detail' => ['other_info' => ['fluent_player_video' => ['media_ids' => implode(',', $ids)]]],
        ]);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(
            array_slice($ids, 0, ProductVideoSettings::MAX_VIDEOS),
            $this->freshOtherInfo($product)['fluent_player_video']['media_ids'],
            'the first MAX_VIDEOS ids are kept, the rest dropped'
        );
    }
}
