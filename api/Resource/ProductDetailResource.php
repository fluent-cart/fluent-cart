<?php

namespace FluentCart\Api\Resource;

use FluentCart\Api\Meta;
use FluentCart\App\Events\StockChanged;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\ProductAdminHelper;
use FluentCart\App\Models\ProductDetail;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ProductDetailResource extends BaseResourceApi
{

    public static function getQuery(): Builder
    {
        return ProductDetail::query();
    }

    public static function get(array $params = [])
    {
        //
    }

    /**
     * Find product detail by its id.
     *
     * @param int $id The id of the product detail.
     * @param array $data Additional data for finding product (optional).
     *
     */
    public static function find($id, $data = [])
    {
        return static::getQuery()->find($id);
    }

    /**
     * Create a new product detail with the given data.
     *
     * @param array $data Array containing the necessary parameters.
     *
     *   $data = [
     *      'post_id'           => (int) Required. The product ID.
     *      'fulfillment_type' => (string) Required. The fulfillment type default:physical.
     *      'variation_type'  => (string) Required. The variation type default:simple.
     *      'manage_stock'  => (int) Required. The manage stock default:1.
     *   ];
     */
    public static function create($data, $params = [])
    {
        $isCreated = static::getQuery()->create($data);

        if ($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('Product has been created successfully', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Product creation failed!', 'fluent-cart')]
        ]);
    }

    /**
     * Update a product detail with the given data.
     * @param int $id The id of the product detail to be updated.
     * @param array $data Array containing the necessary parameters.
     *
     *   $data = [
     *          'id'                => (int) Required. The detail id.
     *          'post_id'           => (int) Required. The product ID.
     *          'fulfillment_type'   => (string) Required. The fulfillment type.
     *          'variation_type'  => (string) Required. The variation type.
     *          'default_variation_id' => (int) Required. The default variation ID.
     *          'manage_stock'  => (int) Required. The manage stock default:1.
     *  ];
     * @param array $params Additional parameters for the update process.
     *  $params = [
     *          'action'  => (string) Required. This param will help to update detail based on the specific action i.e: variant_modified(Triggers when variant modified which covers all mutations), change_variation_type(Triggers when variation type will change).
     *  ];
     */
    public static function update($data, $id, $params = [])
    {
        $data ??= [];

        if (!$id) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Please edit a valid product!', 'fluent-cart')]
            ]);
        }

        $detail = static::getQuery()->find($id);

        if (!$detail) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Product not found, please reload the page and try again!', 'fluent-cart')]
            ]);
        }

        $triggeredAction = Arr::get($params, 'action');

        // Advanced Variations is terminal: once a product uses it, variation_type
        // can never be changed to Simple / Simple Variations — the attribute
        // config and generated combinations are the product's source of truth and
        // a downgrade would orphan them. Guarded on ANY update path that writes
        // variation_type (not just the change_variation_type action) — the full
        // product save also sends variation_type and would otherwise bypass this —
        // and for any API client, not just the disabled admin dropdown. Only an
        // actual downgrade is blocked: re-saving the same advanced type, or an
        // update that omits variation_type, passes through untouched.
        if (
            Arr::has($data, 'variation_type')
            && $detail->variation_type === Helper::PRODUCT_TYPE_ADVANCE_VARIATION
            && Arr::get($data, 'variation_type') !== Helper::PRODUCT_TYPE_ADVANCE_VARIATION
        ) {
            return static::makeErrorResponse([
                ['code' => 422, 'message' => __('A product using Advanced Variations cannot be switched back to Simple or Simple Variations.', 'fluent-cart')]
            ]);
        }

        // Stock & Price Range Handling
        if ($triggeredAction === 'variant_modified') {
            $manageStock = Arr::has($data, 'manage_stock') ? Arr::get($data, 'manage_stock') : $detail->manage_stock;

            if (!$manageStock) {
                $data['stock_availability'] = Helper::IN_STOCK;
            } else {
                $hasInStock = \FluentCart\App\Models\ProductVariation::query()
                    ->where('post_id', $detail->post_id)
                    ->where('stock_status', 'in-stock')
                    ->exists();
                $data['stock_availability'] = $hasInStock ? Helper::IN_STOCK : Helper::OUT_OF_STOCK;
            }
        }

        if ($triggeredAction === 'change_variation_type' && Arr::get($data, 'variation_type') === 'simple') {
            $variationIds = Arr::get($data, 'variation_ids', []);
            if (!empty($detail->post_id) && count($variationIds) > 0) {
                ProductAdminHelper::deleteOrphanVariant(
                    $detail->post_id,
                    $variationIds,
                    __("the product variation type was changed to 'Simple'", 'fluent-cart')
                );

                // The surviving variant (variationIds[0]) is kept, but a Simple
                // product has no editor control to view, replace, or clear its
                // image (VariantTitleMedia only shows for simple_variations /
                // advanced_variations). Leaving that image attached would let it
                // keep rendering on the storefront gallery with no way for the
                // merchant to find or remove it, so it is cleared with the same
                // switch the admin UI now warns about.
                //
                // variation_ids is client-supplied, so confirm the surviving id
                // actually belongs to this product before deleting its media —
                // otherwise a caller could point it at an unrelated product's
                // variation and wipe that variation's image instead.
                $keptVariantBelongsToProduct = \FluentCart\App\Models\ProductVariation::query()
                    ->where('id', $variationIds[0])
                    ->where('post_id', $detail->post_id)
                    ->exists();

                if ($keptVariantBelongsToProduct) {
                    Meta::deleteVariationMedia($variationIds[0]);
                }
            }
        }

        // Switching INTO Advanced Variations (from Simple or Simple Variations)
        // deletes the existing variants now. They have no place in an
        // attribute-based product (the merchant builds fresh combinations from
        // attribute options), and Advanced Variations is terminal so there is
        // nothing to preserve them for — matching the destructive admin confirm
        // ("delete all current variations ... cannot be undone") and the editor
        // clearing them client-side. An empty keep-list deletes every variant for
        // the product; an unconfigured advanced product is hidden on the
        // storefront until the merchant generates combinations, so the empty
        // variant set never leaks. Keyed on the non-advanced -> advanced
        // transition itself, NOT the change_variation_type action, so the side
        // effect is identical on every write path that sets variation_type — the
        // dedicated detail endpoint AND the full pricing save (which calls update()
        // with action=variant_modified). Otherwise a full save or API client could
        // land a product on Advanced Variations without the deletion, leaving
        // inconsistent variant state. Mirrors the downgrade guard above.
        if (
            Arr::get($data, 'variation_type') === Helper::PRODUCT_TYPE_ADVANCE_VARIATION
            && $detail->variation_type !== Helper::PRODUCT_TYPE_ADVANCE_VARIATION
            && !empty($detail->post_id)
        ) {
            ProductAdminHelper::deleteOrphanVariant(
                $detail->post_id,
                [],
                __("the product variation type was changed to 'Advanced Variations'", 'fluent-cart')
            );
        }

        $data['min_price'] = Arr::get($data, 'min_price') ?: ($detail->min_price ?? 0);
        $data['max_price'] = Arr::get($data, 'max_price') ?: ($detail->max_price ?? 0);

        // Handle Default Variation. Only act when the caller actually supplied the
        // key: a partial update that never mentions it must leave the stored value
        // alone, while an explicitly empty value still clears it.
        if (Arr::has($data, 'default_variation_id')) {
            if (empty(Arr::get($data, 'default_variation_id'))) {
                $data['default_variation_id'] = NULL;
            }
        } else {
            unset($data['default_variation_id']);
        }

        // Handle other_info merge
        if (Arr::has($data, 'other_info')) {
            $existingOtherInfo = $detail->other_info ?? [];
            $newOtherInfo = Arr::get($data, 'other_info', []);

            // Merge existing with new data (new data overwrites existing)
            $mergedOtherInfo = array_merge($existingOtherInfo, $newOtherInfo);

            // Handle subscription-specific logic
            if (Arr::get($mergedOtherInfo, 'payment_type') == 'subscription' && Arr::get($mergedOtherInfo, 'manage_setup_fee') == 'yes') {
                // Cents in, and $mergedOtherInfo may carry the already-cents stored
                // value when the caller did not resend signup_fee — roundCent is
                // idempotent, so neither case is rescaled.
                $signupFee = Helper::roundCent(Arr::get($mergedOtherInfo, 'signup_fee', 0));
                $mergedOtherInfo['signup_fee'] = $signupFee;
            }

            $data['other_info'] = $mergedOtherInfo;
        }

        $isUpdated = $detail->update($data);

        if ($isUpdated) {
            return static::makeSuccessResponse($isUpdated, __('Product pricing has been changed!', 'fluent-cart'));
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Product update failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Delete  product detail and its associated data.
     *
     * @param int $id The id of the product detail to be deleted.
     * @param array $params Additional parameters for the deletion process.
     *
     */
    public static function delete($id, $params = [])
    {
        //
    }

}
