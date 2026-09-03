<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Http\Requests\BulkUpdateVariantRequest;
use FluentCart\App\Http\Requests\GroupBulkUpdateVariantRequest;
use FluentCart\App\Http\Requests\ProductVariationRequest;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ProductVariationController extends Controller
{
    public function index(Request $request): array
    {
        // 'params' is optional in the query string; default to an empty array so
        // ProductVariationResource::get() (which type-hints array) never receives
        // null when the endpoint is called without params.
        $parameters = $request->get('params') ?: [];
        $variants = ProductVariationResource::get($parameters);

        return [
            'variants' => $variants['variants'],
        ];
    }

    public function find(Request $request, ProductVariation $product): array
    {
        return [];
    }

    public function create(ProductVariationRequest $request)
    {

        $data = $request->getSafe($request->sanitize());
        $productId = Arr::get($data, 'variants.post_id');


        $product = Product::query()->with('detail')->findOrFail($productId);

        $variationData = Arr::get($data, 'variants', []);
        $otherInfo = is_array(Arr::get($variationData, 'other_info')) ? Arr::get($variationData, 'other_info') : [];
        $otherInfo['is_bundle_product'] = $product->isBundleProduct() ? 'yes' : 'no';
        $variationData['other_info'] = $otherInfo;
        $variationData['detail_id'] = Arr::get($product, 'detail.id', null);

        $isCreated = ProductVariationResource::create($variationData);

        if (is_wp_error($isCreated)) {
            return $isCreated;
        }
        return $this->response->sendSuccess($isCreated);
    }

    public function update(ProductVariationRequest $request, $variantId)
    {

        $data = $request->getSafe($request->sanitize());

        $productId = Arr::get($data, 'variants.post_id');

        $product = Product::query()->with('detail')->findOrFail($productId);

        $isUpdated = ProductVariationResource::update(
            Arr::get($data, 'variants', []), 
            $variantId, 
            [
                'detail_id' => Arr::get($product, 'detail.id', null)
            ]);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function updateTaxSettings(Request $request, $variantId)
    {
        $variantId = absint($variantId);
        $variant = ProductVariation::query()->find($variantId);

        if (!$variant) {
            return $this->sendError([
                'message' => __('Variant not found', 'fluent-cart')
            ]);
        }

        $taxExempt = sanitize_text_field($request->get('tax_exempt', 'no'));
        $taxClassSlug = sanitize_text_field($request->get('tax_class', ''));
        $otherInfo = $variant->other_info ?: [];

        if (!$taxClassSlug) {
            $taxClassSlug = sanitize_text_field(Arr::get($otherInfo, 'tax_class', 'standard'));
        }

        if (!$taxClassSlug) {
            $taxClassSlug = 'standard';
        }

        if (!TaxClass::query()->where('slug', $taxClassSlug)->exists()) {
            return $this->sendError([
                'message' => __('Invalid tax class', 'fluent-cart')
            ], 422);
        }

        $otherInfo['tax_exempt'] = $taxExempt === 'yes' ? 'yes' : 'no';
        $otherInfo['tax_class'] = $taxClassSlug;

        $variant->update([
            'other_info' => $otherInfo
        ]);

        return $this->sendSuccess([
            'message'        => $otherInfo['tax_exempt'] === 'yes'
                ? __('Variation is now tax exempt', 'fluent-cart')
                : __('Tax will be charged on this variation', 'fluent-cart'),
            'tax_exempt'     => $otherInfo['tax_exempt'],
            'tax_class'      => $otherInfo['tax_class'],
            'tax_class_slug' => $otherInfo['tax_class']
        ]);
    }

    public function delete(Request $request, $variantId)
    {
        $variantId = absint($variantId);
        $isDeleted = ProductVariationResource::delete($variantId);

        if (is_wp_error($isDeleted)) {
            return $isDeleted;
        }
        return $this->response->sendSuccess($isDeleted);
    }

    public function setMedia(Request $request, $variantId)
    {
        $variantId = absint($variantId);
        $data = $request->getSafe([
            'media.*.id'    => 'intval',
            'media.*.title' => 'sanitize_text_field',
            'media.*.url'   => function ($value) {
                if (empty($value)) {
                    return '';
                }

                return sanitize_url($value);
            },
        ]);
        $isSetMedia = ProductVariationResource::setImage(Arr::get($data, 'media', []), $variantId);

        if (is_wp_error($isSetMedia)) {
            return $isSetMedia;
        }
        return $this->response->sendSuccess($isSetMedia);
    }

    public function updatePricingTable(Request $request, $variantId)
    {
        $variantId = absint($variantId);
        $data['description'] = sanitize_textarea_field($request->get('description'));

        $isUpdated = ProductVariationResource::updatePricingTable($data, $variantId);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function bulkUpdate(BulkUpdateVariantRequest $request)
    {
        // FormRequest already validated 'updates' is a non-empty array AND
        // capped its size to MAX_UPDATES_PER_REQUEST via sanitize(). Read
        // through getSafe so the capped value flows through, not the raw
        // request input — without this, a caller could POST a 100K-element
        // array and still see it iterated in the loop below.
        $data    = $request->getSafe($request->sanitize());
        $updates = Arr::get($data, 'updates', []);

        if (empty($updates)) {
            return $this->sendError(['message' => __('No updates provided.', 'fluent-cart')], 422);
        }

        // Merge updates that target the same variant id into a single row.
        // Without this, a caller sending [{id:5, item_price:100}, {id:5,
        // compare_price:50}] would produce two separate UPDATE statements:
        // the first sets item_price=100, the second sets compare_price=50.
        // The mirror price-invariant check below reads existing prices ONCE
        // (snapshot from the locked SELECT), so the second row's check
        // compares against the snapshot — NOT the running state from the
        // first row — and can persist compare_price < the just-updated
        // item_price (negative discount, exactly the invariant the price
        // checks exist to prevent). Last-write-wins per field is the
        // expected admin-UI semantic when two payload rows touch the same
        // variant.
        $merged = [];
        foreach ($updates as $update) {
            $id = absint(Arr::get($update, 'id', 0));
            if (!$id) {
                continue;
            }
            if (isset($merged[$id])) {
                $merged[$id] = array_merge($merged[$id], $update);
            } else {
                $merged[$id] = $update;
            }
            $merged[$id]['id'] = $id;
        }
        $updates      = array_values($merged);
        $candidateIds = array_keys($merged);

        if (empty($candidateIds)) {
            return $this->sendError(['message' => __('No valid updates provided.', 'fluent-cart')], 422);
        }

        // Everything from here on — scope check, price preload, sanitization,
        // per-row update — runs INSIDE a single transaction with row-level
        // locks on the candidate variants. Without the locks, two parallel
        // admins editing the same variant set could interleave reads and
        // writes: A reads existing prices, B reads same existing prices,
        // both sanitize based on stale snapshots, and one update silently
        // overwrites the other's compare_price/item_price decision. The
        // ProductDetail save path in syncVariantOption already uses this
        // same lock pattern (round-3 fix) — bringing bulkUpdate into line
        // closes the equivalent gap on the variants table.
        $now             = gmdate('Y-m-d H:i:s');
        $db              = ProductVariation::query()->getConnection();
        $updatedProductId = 0;
        $batchData        = [];
        $db->beginTransaction();
        try {
            // Scope check (locked). Every variant ID in the batch must
            // (a) exist and (b) belong to the same product. Without (b)
            // a caller with the generic products/edit capability could
            // mix IDs from multiple products in one request and modify
            // variants on products they were never working on
            // (cross-product side-channel via the bulk endpoint).
            // Loaded with lockForUpdate so the existing item_price /
            // compare_price values used below as baselines for the
            // price-relationship checks reflect the committed state at
            // write time, not a stale pre-transaction read.
            $ownedRows = ProductVariation::query()
                ->whereIn('id', $candidateIds)
                ->lockForUpdate()
                ->get(['id', 'post_id', 'item_price', 'compare_price']);

            if ($ownedRows->count() !== count($candidateIds)) {
                $db->rollBack();
                return $this->sendError([
                    'message' => __('One or more variant IDs do not exist.', 'fluent-cart'),
                ], 404);
            }

            $distinctPostIds = $ownedRows->pluck('post_id')->unique();
            if ($distinctPostIds->count() !== 1) {
                $db->rollBack();
                return $this->sendError([
                    'message' => __('All updates must target variants on the same product.', 'fluent-cart'),
                ], 422);
            }
            $updatedProductId = (int) $distinctPostIds->first();

            // Maps of existing prices (in cents, as stored). Used to validate
            // BOTH directions of the price-relationship invariant:
            //   - compare_price set without item_price → use existing item_price
            //     as the baseline so a low compare_price below the persisted
            //     item_price is rejected (round 4 fix).
            //   - item_price set without compare_price → check that the new
            //     item_price doesn't leave the persisted compare_price below
            //     it (round 5 mirror; same invariant, opposite direction).
            $existingItemPriceCents    = [];
            $existingComparePriceCents = [];
            foreach ($ownedRows as $variant) {
                $vid = (int) $variant->id;
                $existingItemPriceCents[$vid]    = (int) $variant->item_price;
                $existingComparePriceCents[$vid] = (int) $variant->compare_price;
            }

            $allowedStatuses      = ['active', 'inactive'];

            foreach ($updates as $update) {
                $id = absint(Arr::get($update, 'id', 0));
                if (!$id) {
                    continue;
                }

                $row = ['id' => $id];

                if (array_key_exists('item_price', $update)) {
                    // Submitted in CENTS. roundCent() normalizes float artifacts
                    // without scaling; it does not multiply by 100.
                    $itemPriceCentsIn = floatval($update['item_price']);
                    // Reject negative prices outright rather than coerce to 0 —
                    // a caller submitting -50 has either bad client logic or
                    // hostile intent; either way we should not silently
                    // substitute a price they didn't choose.
                    if ($itemPriceCentsIn >= 0) {
                        $row['item_price'] = Helper::roundCent($itemPriceCentsIn);
                    }
                }

                if (array_key_exists('compare_price', $update)) {
                    $comparePriceCentsIn = floatval($update['compare_price']);
                    // Mirror of the item_price negative guard. compare_price=0
                    // is a valid "no discount" sentinel; negative is not.
                    if ($comparePriceCentsIn >= 0) {
                        $comparePriceCents = Helper::roundCent($comparePriceCentsIn);
                        // Effective item_price (in cents) for the comparison:
                        // the new value if this update sets it (and is valid),
                        // otherwise the already-persisted value from the DB.
                        // Falling back to 0 would re-introduce the bypass
                        // where compare_price could land below the existing
                        // item_price.
                        $itemPriceCents = array_key_exists('item_price', $row)
                            ? (int) $row['item_price']
                            : ($existingItemPriceCents[$id] ?? 0);

                        $row['compare_price'] = ($comparePriceCents > 0 && (!$itemPriceCents || $comparePriceCents >= $itemPriceCents))
                            ? $comparePriceCents
                            : 0;
                    }
                }

                // Mirror invariant: if the caller raised item_price WITHOUT
                // touching compare_price, and the persisted compare_price is
                // now below the new item_price, zero compare_price out in the
                // same UPDATE. Without this, raising item_price alone leaves
                // a stale compare_price < item_price (a negative discount the
                // storefront would render as garbage).
                if (array_key_exists('item_price', $row) && !array_key_exists('compare_price', $row)) {
                    $existingCompare = $existingComparePriceCents[$id] ?? 0;
                    if ($existingCompare > 0 && $existingCompare < (int) $row['item_price']) {
                        $row['compare_price'] = 0;
                    }
                }

                if (array_key_exists('item_status', $update)) {
                    $status = sanitize_text_field($update['item_status']);
                    if (in_array($status, $allowedStatuses)) {
                        $row['item_status'] = $status;
                    }
                }

                if (count($row) > 1) {
                    $batchData[] = $row;
                }
            }

            if (empty($batchData)) {
                $db->rollBack();
                return $this->sendError(['message' => __('No valid updates provided.', 'fluent-cart')], 422);
            }

            // Per-row UPDATE (not one bulk statement) because each row may
            // have a different subset of columns to update. Inside the same
            // transaction as the locked scope-check above, so a mid-loop
            // failure rolls back the whole batch — no partial commit.
            foreach ($batchData as $row) {
                $id = (int) $row['id'];
                unset($row['id']);
                if (empty($row)) {
                    continue;
                }
                // Stamp updated_at explicitly — the query-builder update
                // bypasses Eloquent's auto-timestamps (model events,
                // observers, $timestamps property). Without this, every
                // bulk-edited variant would keep its old updated_at and
                // forensics / cache-invalidation that relies on the
                // timestamp would silently miss the change.
                $row['updated_at'] = $now;
                ProductVariation::query()->where('id', $id)->update($row);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return $this->sendError([
                'message' => __('Failed to update variants.', 'fluent-cart'),
            ], 500);
        }

        // Mirror the free-side canonical variant-update event so cache
        // invalidators, search indexers, audit loggers, and webhook
        // subscribers listening on this hook see our bulk writes too.
        // Free fires this from ProductResource.php after its non-advanced
        // batchUpdate; without firing it here, our writes are silent.
        do_action('fluent_cart/product/variants_updated', [
            'post_id'  => $updatedProductId,
            'variants' => $batchData,
        ]);

        return $this->sendSuccess([
            'message' => __('Variants updated successfully.', 'fluent-cart'),
            'updated' => count($batchData),
        ]);
    }

    /**
     * Group bulk update — partial update with PATCH semantics.
     * Any field left null in the payload is skipped; only provided non-null
     * fields are written to each variant in the group. For other_info, only
     * the supplied non-null sub-keys are merged into the existing JSON.
     */
    public function groupBulkUpdate(GroupBulkUpdateVariantRequest $request)
    {
        $data       = $request->getSafe($request->sanitize());
        $variantIds = Arr::get($data, 'variant_ids', []);

        if (empty($variantIds)) {
            return $this->sendError(['message' => __('No valid variant IDs provided.', 'fluent-cart')], 422);
        }

        $raw            = $request->all();
        $topLevelDelta  = [];
        $otherInfoDelta = null;

        $itemPrice = Arr::get($raw, 'item_price');
        if ($itemPrice !== null && $itemPrice !== '') {
            $price = floatval($itemPrice);
            if ($price >= 0) {
                $topLevelDelta['item_price'] = Helper::roundCent($price);
            }
        }

        $comparePrice = Arr::get($raw, 'compare_price');
        if ($comparePrice !== null && $comparePrice !== '') {
            $compare = floatval($comparePrice);
            if ($compare >= 0) {
                $topLevelDelta['_compare_price_cents'] = $compare;
            }
        }

        // SKU uniqueness — only apply to a single variant to avoid duplicates.
        // An empty string means "clear the SKU" (stored as NULL; MySQL NULL is unique-safe).
        // Read from $data (post-validation, post-sanitization) not $raw.
        if (count($variantIds) === 1 && array_key_exists('sku', $data)) {
            $topLevelDelta['sku'] = Arr::get($data, 'sku');
        }

        $manageStock = Arr::get($raw, 'manage_stock');
        if ($manageStock !== null) {
            $topLevelDelta['manage_stock'] = (int) $manageStock;
        }

        $totalStock = Arr::get($raw, 'total_stock');
        if ($totalStock !== null && $totalStock !== '') {
            $topLevelDelta['total_stock'] = absint($totalStock);
        }

        $fulfillmentType = Arr::get($raw, 'fulfillment_type');
        if ($fulfillmentType !== null && $fulfillmentType !== '') {
            $val = sanitize_text_field($fulfillmentType);
            if (in_array($val, ['physical', 'digital'], true)) {
                $topLevelDelta['fulfillment_type'] = $val;
            }
        }

        $manageCost = Arr::get($raw, 'manage_cost');
        if ($manageCost !== null && $manageCost !== '') {
            $val = sanitize_text_field($manageCost);
            if (in_array($val, ['true', 'false'], true)) {
                $topLevelDelta['manage_cost'] = $val;
            }
        }

        $itemCost = Arr::get($raw, 'item_cost');
        if ($itemCost !== null && $itemCost !== '') {
            $cost = floatval($itemCost);
            if ($cost >= 0) {
                $topLevelDelta['item_cost'] = Helper::roundCent($cost);
            }
        }

        $rawOtherInfo = Arr::get($raw, 'other_info');
        if (is_array($rawOtherInfo)) {
            $otherInfoDelta = $this->sanitizeOtherInfoDelta($rawOtherInfo);
        }

        if (empty($topLevelDelta) && ($otherInfoDelta === null || empty($otherInfoDelta))) {
            return $this->sendError(['message' => __('No valid updates provided.', 'fluent-cart')], 422);
        }

        // Setting variants to subscription requires a billing interval in the
        // same request — a subscription without one can never bill. Checked
        // before the transaction so bad input fails fast with no rollback.
        // (An invalid interval was already dropped by sanitizeOtherInfoDelta.)
        if (is_array($otherInfoDelta)
            && Arr::get($otherInfoDelta, 'payment_type') === 'subscription'
            && empty($otherInfoDelta['repeat_interval'])
        ) {
            return $this->sendError(['message' => __('A valid billing interval is required for subscription variants.', 'fluent-cart')], 422);
        }

        $db               = ProductVariation::query()->getConnection();
        $now              = gmdate('Y-m-d H:i:s');
        $updatedProductId = 0;
        $batchData        = [];

        $db->beginTransaction();
        try {
            $ownedRows = ProductVariation::query()
                ->whereIn('id', $variantIds)
                ->lockForUpdate()
                ->get(['id', 'post_id', 'item_price', 'compare_price', 'other_info', 'manage_stock', 'total_stock', 'payment_type']);

            if ($ownedRows->count() !== count($variantIds)) {
                $db->rollBack();
                return $this->sendError(['message' => __('One or more variant IDs do not exist.', 'fluent-cart')], 404);
            }

            $distinctPostIds = $ownedRows->pluck('post_id')->unique();
            if ($distinctPostIds->count() !== 1) {
                $db->rollBack();
                return $this->sendError(['message' => __('All variants must belong to the same product.', 'fluent-cart')], 422);
            }
            $updatedProductId = (int) $distinctPostIds->first();

            // Prepare pass: build and validate every row update BEFORE writing
            // anything, so a validation failure returns early with no UPDATE
            // executed (the rollbacks below only release the row locks).
            $preparedUpdates = [];

            foreach ($ownedRows as $existingVariant) {
                $vid       = (int) $existingVariant->id;
                $rowUpdate = [];

                if (isset($topLevelDelta['item_price'])) {
                    $rowUpdate['item_price'] = $topLevelDelta['item_price'];
                }

                if (isset($topLevelDelta['_compare_price_cents'])) {
                    $compareCents   = Helper::roundCent($topLevelDelta['_compare_price_cents']);
                    $itemPriceCents = isset($rowUpdate['item_price'])
                        ? (int) $rowUpdate['item_price']
                        : (int) $existingVariant->item_price;
                    $rowUpdate['compare_price'] = ($compareCents > 0 && $compareCents >= $itemPriceCents)
                        ? $compareCents
                        : 0;
                } elseif (isset($rowUpdate['item_price'])) {
                    $existingCompare = (int) $existingVariant->compare_price;
                    if ($existingCompare > 0 && $existingCompare < $rowUpdate['item_price']) {
                        $rowUpdate['compare_price'] = 0;
                    }
                }

                foreach (['sku', 'manage_stock', 'total_stock', 'fulfillment_type', 'manage_cost', 'item_cost'] as $field) {
                    if (array_key_exists($field, $topLevelDelta)) {
                        $rowUpdate[$field] = $topLevelDelta[$field];
                    }
                }

                if (isset($rowUpdate['manage_stock']) || isset($rowUpdate['total_stock'])) {
                    $manageStock = isset($rowUpdate['manage_stock']) ? $rowUpdate['manage_stock'] : (int) $existingVariant->manage_stock;
                    $totalStock  = isset($rowUpdate['total_stock']) ? $rowUpdate['total_stock'] : (int) $existingVariant->total_stock;
                    $rowUpdate['stock_status'] = ($manageStock && $totalStock > 0) ? Helper::IN_STOCK : Helper::OUT_OF_STOCK;
                    if (!$manageStock) {
                        $rowUpdate['stock_status'] = Helper::IN_STOCK;
                    }
                }

                if ($otherInfoDelta !== null && !empty($otherInfoDelta)) {
                    $existingOtherInfo = is_array($existingVariant->other_info) ? $existingVariant->other_info : [];
                    $merged            = array_merge($existingOtherInfo, $otherInfoDelta);

                    // Prefer payment_type from the merged other_info; fall back
                    // to the top-level column so signup_fee is converted to cents
                    // even when the request omits payment_type entirely.
                    $paymentType = Arr::get($merged, 'payment_type') ?: $existingVariant->payment_type;
                    if ($paymentType === 'onetime') {
                        foreach (['repeat_interval', 'interval', 'interval_count', 'billing_summary',
                                  'manage_setup_fee', 'signup_fee', 'signup_fee_name', 'times', 'trial_days'] as $subKey) {
                            unset($merged[$subKey]);
                        }
                    }

                    if ($paymentType === 'subscription' && array_key_exists('signup_fee', $otherInfoDelta)) {
                        $merged['signup_fee'] = Helper::roundCent($otherInfoDelta['signup_fee']);
                    }

                    // `installment` is not an accepted delta key (see sanitizeOtherInfoDelta),
                    // so the stored flag on the row decides whether this is an installment
                    // plan. Re-check only when the request changes `times`, so an unrelated
                    // bulk price edit on a legacy row still saves. The payment_type gate
                    // matters: `times` is stripped from $merged for a one-time variant
                    // above, while a stale `installment` may survive in its stored JSON.
                    if ($paymentType === 'subscription' && array_key_exists('times', $otherInfoDelta)) {
                        $timesError = Helper::installmentTimesError($merged);
                        if ($timesError) {
                            $db->rollBack();
                            return $this->sendError(['message' => $timesError], 422);
                        }
                    }

                    // billing_summary embeds the row's own price, so one client-sent
                    // value can never fit a group of variants with different prices —
                    // recompute per row from the effective price/interval/times.
                    if ($paymentType === 'subscription') {
                        $effectivePriceCents = isset($rowUpdate['item_price'])
                            ? (int) $rowUpdate['item_price']
                            : (int) $existingVariant->item_price;
                        $merged['billing_summary'] = $this->buildBillingSummary($effectivePriceCents, $merged);
                    }

                    $merged['is_bundle_product'] = Arr::get($existingOtherInfo, 'is_bundle_product', 'no');
                    $merged['bundle_child_ids']   = Arr::get($existingOtherInfo, 'bundle_child_ids', []);

                    $rowUpdate['other_info'] = $merged;

                    if (isset($otherInfoDelta['payment_type'])) {
                        $rowUpdate['payment_type'] = $otherInfoDelta['payment_type'] === 'subscription'
                            ? 'subscription'
                            : 'onetime';
                    }
                } elseif (isset($rowUpdate['item_price']) && $existingVariant->payment_type === 'subscription') {
                    // Price-only bulk edit on a subscription row: the stored
                    // summary embeds the old price — refresh it from the new one.
                    // Write back the raw stored JSON, not the accessor output:
                    // getOtherInfoAttribute() injects virtual defaults (and
                    // downgrades installment to 'no' while Pro is inactive) that
                    // an unrelated price edit must not persist.
                    $rawOtherInfoJson = Arr::get($existingVariant->getAttributes(), 'other_info');
                    $rawOtherInfo = (is_string($rawOtherInfoJson) && $rawOtherInfoJson !== '')
                        ? json_decode($rawOtherInfoJson, true)
                        : [];
                    $rawOtherInfo = is_array($rawOtherInfo) ? $rawOtherInfo : [];
                    $accessorOtherInfo = is_array($existingVariant->other_info) ? $existingVariant->other_info : [];
                    $rawOtherInfo['billing_summary'] = $this->buildBillingSummary((int) $rowUpdate['item_price'], $accessorOtherInfo);
                    $rowUpdate['other_info'] = $rawOtherInfo;
                }

                if (!empty($rowUpdate)) {
                    $rowUpdate['updated_at'] = $now;
                    $preparedUpdates[$vid] = $rowUpdate;
                }
            }

            // Write pass: every row validated above, apply the updates.
            foreach ($preparedUpdates as $vid => $rowUpdate) {
                ProductVariation::query()->where('id', $vid)->update($rowUpdate);
                $batchData[] = array_merge(['id' => $vid], $rowUpdate);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return $this->sendError(['message' => __('Failed to update variants.', 'fluent-cart')], 500);
        }

        do_action('fluent_cart/product/variants_updated', [
            'post_id'  => $updatedProductId,
            'variants' => $batchData,
        ]);

        /* translators: %1$s: number of variants updated */
        return $this->sendSuccess([
            'message' => sprintf(__('%1$s variants updated successfully.', 'fluent-cart'), count($variantIds)),
            'updated' => count($variantIds),
        ]);
    }

    /**
     * Build the per-variant billing summary string, mirroring the admin JS
     * (ProductEditModel.onChangePricingPayment): "{price} {interval} {occurrence}".
     */
    private function buildBillingSummary($priceCents, array $otherInfo)
    {
        $interval = Arr::get($otherInfo, 'repeat_interval', '');
        if (!$interval) {
            return '';
        }

        // A valid installment count is always >= 2 (Helper::installmentTimesError);
        // legacy garbage like 1 or -1 must not surface as "for -1 Times".
        $times = (int) Arr::get($otherInfo, 'times', 0);
        $occurrence = $times >= 2
            /* translators: %1$s: number of installment payments */
            ? sprintf(__('for %1$s Times', 'fluent-cart'), $times)
            : __('Until Cancel', 'fluent-cart');

        $price = 0 + round(((int) $priceCents) / 100, 2);

        /* translators: %1$s: price, %2$s: billing interval (e.g. monthly), %3$s: occurrence (e.g. Until Cancel) */
        return sprintf(__('%1$s %2$s %3$s', 'fluent-cart'), $price, $interval, $occurrence);
    }

    /**
     * Sanitize the other_info delta for group bulk update.
     * Only known sub-keys are allowed; unknown keys are dropped to prevent
     * arbitrary data injection into the JSON column.
     */
    private function sanitizeOtherInfoDelta(array $raw)
    {
        // billing_summary is intentionally NOT accepted — it embeds each row's
        // own price, so groupBulkUpdate() recomputes it server-side per variant.
        $allowed = [
            'description'      => 'sanitize_textarea_field',
            'tax_inclusion'    => 'sanitize_text_field',
            'package_slug'     => 'sanitize_text_field',
            'weight_unit'      => 'sanitize_text_field',
            'manage_setup_fee' => 'sanitize_text_field',
            'signup_fee_name'  => 'sanitize_text_field',
            'times'            => 'sanitize_text_field',
            'repeat_interval'  => 'sanitize_text_field',
            'interval'         => 'sanitize_text_field',
        ];
        $numericFields = ['weight', 'length', 'width', 'height'];
        $intFields     = ['interval_count', 'trial_days'];

        $delta = [];

        foreach ($allowed as $key => $sanitizer) {
            $value = Arr::get($raw, $key);
            if ($value === null || $value === '') {
                continue;
            }
            $delta[$key] = $sanitizer($value);
        }

        // repeat_interval is an enum, not free text — an unknown value would be
        // stored verbatim and surface in billing summaries ("9.99 garbage …").
        if (isset($delta['repeat_interval'])) {
            $validIntervals = array_column(Helper::getAvailableSubscriptionIntervalOptions(), 'value');
            if (!in_array($delta['repeat_interval'], $validIntervals, true)) {
                unset($delta['repeat_interval']);
            }
        }

        // Enum-validated fields — unknown values are dropped rather than stored.
        $paymentType = Arr::get($raw, 'payment_type');
        if ($paymentType !== null && $paymentType !== '') {
            $paymentType = sanitize_text_field($paymentType);
            if (in_array($paymentType, ['onetime', 'subscription'], true)) {
                $delta['payment_type'] = $paymentType;
            }
        }

        $taxExempt = Arr::get($raw, 'tax_exempt');
        if ($taxExempt !== null) {
            $delta['tax_exempt'] = sanitize_text_field($taxExempt) === 'yes' ? 'yes' : 'no';
        }

        $taxClass = Arr::get($raw, 'tax_class');
        if ($taxClass !== null && $taxClass !== '') {
            $taxClass = sanitize_text_field($taxClass);
            if (TaxClass::query()->where('slug', $taxClass)->exists()) {
                $delta['tax_class'] = $taxClass;
            }
        }

        foreach ($numericFields as $key) {
            $value = Arr::get($raw, $key);
            if ($value === null || $value === '') {
                continue;
            }
            $delta[$key] = floatval($value);
        }

        // signup_fee arrives in cents; groupBulkUpdate() normalizes it with
        // Helper::roundCent() when payment_type is subscription.
        $signupFee = Arr::get($raw, 'signup_fee');
        if ($signupFee !== null && $signupFee !== '') {
            $delta['signup_fee'] = floatval($signupFee);
        }

        foreach ($intFields as $key) {
            $value = Arr::get($raw, $key);
            if ($value === null || $value === '') {
                continue;
            }
            $delta[$key] = intval($value);
        }

        return $delta;
    }
}
