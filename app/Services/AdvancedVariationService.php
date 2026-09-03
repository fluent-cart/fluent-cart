<?php

namespace FluentCart\App\Services;

use FluentCart\Api\Resource\ProductMetaResource;
use FluentCart\App\Events\ProductVariationsChanged;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\ProductAdminHelper;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;

class AdvancedVariationService
{
    /**
     * Hard ceiling on the number of variant combinations a single save may
     * generate. The cartesian product of attribute groups grows multiplicatively
     * (3 groups × 10 terms each = 1,000 variants, 4 × 10 = 10,000), so without
     * a cap a careless attribute set can blow up admin save time, memory,
     * and downstream DB write volume. 500 is generous for real e-commerce use
     * (typical: <50 combinations) while still bounding the worst case.
     * Filterable so a high-SKU store can raise it after a deliberate review.
     */
    const DEFAULT_MAX_COMBINATIONS = 500;

    /**
     * Hard ceiling on the number of attribute groups a single save may
     * reference. Caps the cartesian's depth AND bounds the length of the
     * variation_identifier string (joined term IDs — the column is
     * VARCHAR(100) on free, so ~5 BIGINT-sized IDs is the schema limit
     * anyway). Without this, a payload with many single-term groups would
     * pass the combination cap but still drive an unbounded identifier
     * concatenation, whereIn list, and relation insert per variant.
     */
    const DEFAULT_MAX_GROUPS_PER_SAVE = 10;

    /**
     * Hard ceiling on the total unique term IDs the payload may reference
     * (sum across all groups, deduplicated). Bounds the size of the
     * findMissingTermIds whereIn lookup and the per-variant relation
     * insert volume even when the cartesian cap is satisfied (the
     * single-term-many-groups bypass the bot flagged).
     */
    const DEFAULT_MAX_TERMS_PER_SAVE = 500;

    /**
     * Matches fc_product_variations.variation_identifier (VARCHAR(100) on
     * free). Used by the runtime guard in syncVariantCombinations to abort
     * the save if any cartesian combination's underscore-joined term IDs
     * would overflow the column. At the group cap (10) with realistic
     * 5-8 digit term IDs we land at ~89 chars; the guard exists to catch
     * the pathological case of growth-rotated BIGINT IDs in long-lived
     * stores (>10-digit IDs aren't unprecedented). Non-STRICT MySQL would
     * silently truncate, creating duplicate variants on different
     * cartesian combinations that collapse to the same identifier prefix.
     */
    const VARIATION_IDENTIFIER_MAX_LENGTH = 100;

    /**
     * Baseline keys every variant's `other_info` carries — mirrors the
     * payload that ProductBaseModel.addDummyVariant ships for simple /
     * simple_variations products. The advanced-variation create path used
     * to write only `{"variant": [...]}`, so downstream consumers that
     * read `other_info.payment_type` (admin order update, cart line
     * generation, OrderItemResource) got NULL and tripped the
     * fct_order_items.payment_type NOT NULL constraint. Keep the keys in
     * sync with the JS factory so the two surfaces don't drift.
     */
    private static function defaultVariantOtherInfo(): array
    {
        return [
            'description'        => '',
            'payment_type'       => 'onetime',
            'tax_class'          => 'standard',
            'tax_exempt'         => 'no',
            'tax_inclusion'      => '',
            'times'              => '',
            'repeat_interval'    => 'yearly',
            'billing_summary'    => '',
            'manage_setup_fee'   => 'no',
            'signup_fee_name'    => '',
            'signup_fee'         => '',
            'setup_fee_per_item' => 'no',
            'package_slug'       => '',
            'weight'             => null,
        ];
    }

    public static function syncVariantOption(int $productId, array $data): array
    {
        $settings   = Arr::get($data, 'options');
        $srcPricing = ProductDetail::where('post_id', $productId)->first();

        if (!$srcPricing) {
            return [
                'message' => __('Product details not found.', 'fluent-cart'),
            ];
        }

        // Cap the cartesian explosion BEFORE any DB work. Compute the
        // projected combination count from the raw payload — multiplying
        // the size of each non-empty group — and reject early if it exceeds
        // the configured maximum. Without this guard, a 5×10 attribute set
        // would attempt to materialise 100,000 variants per save: minutes
        // of CPU, hundreds of MB of arrays, and a request that never
        // returns. The check is intentionally on the projection, not on
        // the actual generated set, so we reject before generateVariationSets()
        // ever runs.
        //
        // Whitelist-sanitize the payload BEFORE every downstream consumer
        // (cap checks, validation, storage) sees it. The raw $settings is
        // caller-controlled and lands in ProductDetail.other_info.attribute_config
        // via the save() below; without filtering, a payload like
        // [{"variants": [...], "price_override": 999, "_debug": "..."}]
        // would persist arbitrary keys into other_info — polluting the
        // canonical store and giving downstream consumers an attack surface
        // that bypasses every layer above. Only known-good keys (variants,
        // group_id) survive; everything else is dropped.
        $settingsArr = self::sanitizeSettings($settings);

        // Shape caps — bound the payload dimensions BEFORE the combination
        // cap below. Without these, a payload with many single-term groups
        // (e.g. 1000 groups × 1 term each = 1 variant) would slip past
        // the combination cap and still drive an unbounded variation_identifier
        // string, term-validation whereIn list, and per-variant relation
        // insert count. The combination cap only bounds the cartesian
        // product; these caps bound the per-variant cost.
        $maxGroups = (int) apply_filters(
            'fluent_cart/advanced_variation/max_groups_per_save',
            self::DEFAULT_MAX_GROUPS_PER_SAVE
        );
        $groupCount = self::countNonEmptyGroups($settingsArr);
        if ($groupCount > $maxGroups) {
            return [
                'message' => sprintf(
                    /* translators: 1: provided group count, 2: configured maximum */
                    __('Too many attribute groups (%1$d). The maximum allowed is %2$d per save. Reduce the number of groups, or raise the limit via the fluent_cart/advanced_variation/max_groups_per_save filter.', 'fluent-cart'),
                    $groupCount,
                    $maxGroups
                ),
            ];
        }

        $maxTerms = (int) apply_filters(
            'fluent_cart/advanced_variation/max_terms_per_save',
            self::DEFAULT_MAX_TERMS_PER_SAVE
        );
        $uniqueTermCount = self::countUniqueTermIds($settingsArr);
        if ($uniqueTermCount > $maxTerms) {
            return [
                'message' => sprintf(
                    /* translators: 1: total unique term count in payload, 2: configured maximum */
                    __('Too many attribute terms (%1$d) referenced. The maximum allowed is %2$d unique terms per save. Trim the term selection, or raise the limit via the fluent_cart/advanced_variation/max_terms_per_save filter.', 'fluent-cart'),
                    $uniqueTermCount,
                    $maxTerms
                ),
            ];
        }

        $maxCombinations = (int) apply_filters(
            'fluent_cart/advanced_variation/max_combinations',
            self::DEFAULT_MAX_COMBINATIONS
        );
        $projected = self::projectCombinationCount($settingsArr);
        if ($projected > $maxCombinations) {
            return [
                'message' => sprintf(
                    /* translators: 1: projected combination count, 2: configured maximum */
                    __('Too many variation combinations (%1$d). The maximum allowed is %2$d. Reduce attribute groups or terms, or raise the limit via the fluent_cart/advanced_variation/max_combinations filter.', 'fluent-cart'),
                    $projected,
                    $maxCombinations
                ),
            ];
        }

        // Validate that every term ID in the payload actually exists in
        // fct_atts_terms BEFORE any variant is created. Without this guard,
        // a payload referencing an unknown term ID (forged, stale UI, race
        // with a delete) would still create variants for combinations
        // containing that ID — the relation insert silently skips the
        // unknown term (since $termMap->get() returns null), leaving an
        // orphan variant row with incomplete attr_map. Reject the whole
        // request so the caller fixes the payload before any DB write.
        $missingTerms = self::findMissingTermIds($settingsArr);
        if (!empty($missingTerms)) {
            return [
                'message' => sprintf(
                    /* translators: 1: comma-separated list of unknown attribute term IDs */
                    __('Unknown attribute terms in payload: %1$s. Refresh the editor and try again.', 'fluent-cart'),
                    implode(', ', array_map('intval', $missingTerms))
                ),
            ];
        }

        // Wrap the variant + relation sync AND the ProductDetail save in a
        // single transaction. Without this, a failure on the final save
        // would leave variants and relations on the new attribute_config
        // while ProductDetail.other_info still points at the old config —
        // exactly the inconsistent state the admin UI cannot recover from.
        $db = ProductDetail::query()->getConnection();
        $db->beginTransaction();
        try {
            // Re-fetch with lockForUpdate so two parallel saves on the
            // same product serialize on this row. Without the lock,
            // both saves would each call syncVariantCombinations and
            // both write variants — fct_product_variations.variation_identifier
            // has no UNIQUE constraint, so the duplicates land cleanly,
            // creating two rows for every cartesian combination.
            $srcPricing = ProductDetail::query()
                ->where('post_id', $productId)
                ->lockForUpdate()
                ->first();

            if (!$srcPricing) {
                $db->rollBack();
                return [
                    'message' => __('Product details not found.', 'fluent-cart'),
                ];
            }

            // Pass $settingsArr (already validated to be an array by the cap
            // checks above) rather than the raw $settings — if the caller
            // omitted the 'options' key entirely, $settings is null and the
            // foreach inside syncVariantCombinations would warn before the
            // empty-cartesian short-circuit caught it.
            $syncResult = self::syncVariantCombinations($srcPricing, $settingsArr);
            $variants   = $syncResult['variants'];

            $existingOtherInfo = $srcPricing->other_info;
            if (!is_array($existingOtherInfo)) {
                $existingOtherInfo = [];
            }
            // Store the DB-DERIVED real-group structure as attribute_config,
            // not the raw caller payload. A spoofed payload that claimed
            // wrong group_ids would otherwise persist its lie into other_info
            // even though the cartesian (and the variant.attr_map relations)
            // already reflect DB truth. Reading attribute_config later must
            // give the same answer as reading the relations — they're two
            // views of the same canonical fact.
            $existingOtherInfo['attribute_config'] = $syncResult['attribute_config'];

            $srcPricing->fill([
                'other_info'     => $existingOtherInfo,
                'variation_type' => Helper::PRODUCT_TYPE_ADVANCE_VARIATION,
            ])->save();

            $db->commit();
        } catch (\RuntimeException $e) {
            $db->rollBack();
            // RuntimeException is OUR validation surface — every throw in
            // syncVariantCombinations (cap exceeded, identifier overflow,
            // term-drift during sync, etc.) is an actionable, merchant-safe
            // message we authored ourselves. Surface it so the caller knows
            // which limit they hit and how to fix it. Distinct from the
            // generic Throwable catch below which suppresses driver-level
            // messages that could leak query fragments / file paths.
            return [
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            // Non-RuntimeException — driver errors, unexpected fatals, etc.
            // Intentionally do NOT include $e->getMessage() — could leak
            // query fragments, file paths, and column names to the API
            // client. Matches the AttrGroupResource / AttrTermResource
            // catch-block pattern from the Attributes module.
            return [
                'message' => __('Failed to update variation combination.', 'fluent-cart'),
            ];
        }

        // Mirror the free-side canonical variant-update event so cache
        // invalidators, search indexers, audit loggers, and webhook
        // subscribers listening on this hook see our cartesian writes too.
        // Free fires this from ProductResource.php after its non-advanced
        // variant batchUpdate; without firing it here, the advanced-variation
        // path becomes a silent fork of the standard variant-save flow.
        do_action('fluent_cart/product/variants_updated', [
            'post_id'  => $productId,
            'variants' => $variants ? $variants->toArray() : [],
        ]);

        // Re-resolve default_variation_id against the new combination set —
        // generate / reorder / add / update / remove all land here. The default
        // is always the first combination by serial_index (UpdateDefaultVariation,
        // this event's listener), so reordering redefines it and it ignores
        // stock/active state.
        (new ProductVariationsChanged([$productId]))->dispatch();

        return [
            'message' => __('Variation combination updated!', 'fluent-cart'),
            'data'    => $variants,
        ];
    }

    private static function syncVariantCombinations($srcDetails, $variations)
    {
        // Extract every term ID from the payload — flat, unique, positive only.
        // The cartesian dimensions below are derived from the DB-truth grouping
        // of these IDs, NOT from the caller-claimed payload structure. This is
        // the canonical fix for the trust attack: a payload that claims
        // [{group_id: 99, variants: [10]}, {group_id: 100, variants: [20]}]
        // when terms 10 and 20 actually both live in group 5 would otherwise
        // produce a 2-dimensional cartesian (instead of the 1 real dimension)
        // and generate variants claiming two terms from group 5 each. Build
        // dimensions from $termMap.group_id below instead — payload group_id
        // becomes pure display metadata.
        $payloadTermIds = [];
        // First-appearance rank of each term id across the payload. The merchant
        // controls this order by drag-reordering the option *values* inside a
        // card; it drives the within-group term order of the cartesian below and
        // therefore the serial_index of each generated combination. This is the
        // product-level order (persisted in other_info.attribute_config), NOT the
        // library-wide fct_atts_terms.serial — reordering here never affects any
        // other product using the same attribute group.
        $payloadTermOrder = [];
        foreach ($variations as $variation) {
            if (empty($variation['variants']) || !is_array($variation['variants'])) {
                continue;
            }
            foreach ($variation['variants'] as $termId) {
                $id = (int) $termId;
                if ($id > 0) {
                    $payloadTermIds[$id] = true;
                    if (!isset($payloadTermOrder[$id])) {
                        $payloadTermOrder[$id] = count($payloadTermOrder);
                    }
                }
            }
        }
        $payloadTermIds = array_keys($payloadTermIds);

        $srcDetails->load('product');
        // Defensive: $srcDetails->product can be null if the underlying
        // wp_posts row was deleted between the ProductDetail lookup and
        // here. Fall back to an empty title rather than fatal — the
        // variant rows will still be valid, the merchant can fix the
        // title on next save.
        $variationTitle = $srcDetails->product ? $srcDetails->product->post_title : '';
        $variantIds     = [];

        // Empty payload means the merchant cleared the variation set —
        // ProductAdminHelper::deleteOrphanVariant with an empty keep-list
        // removes every variant on the product. Return early; everything
        // below would no-op anyway.
        if (empty($payloadTermIds)) {
            ProductAdminHelper::deleteOrphanVariant($srcDetails->post_id, []);
            return [
                'variants'         => new \FluentCart\Framework\Support\Collection(),
                'attribute_config' => [],
            ];
        }

        // Load the referenced terms with lockForUpdate — locks all referenced
        // term rows for the duration of this transaction. AttributeRelation
        // has no FK on term_id, so a parallel DELETE that beats our SELECT
        // would otherwise leave the bulk insert below pointing at a stale
        // term_id (a permanent orphan). The pre-flight findMissingTermIds in
        // syncVariantOption already filters unknown IDs before the txn opens;
        // this lock + the count check below close the TOCTOU window.
        $termMap = AttributeTerm::query()
            ->whereIn('id', $payloadTermIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if (count($termMap) !== count($payloadTermIds)) {
            throw new \RuntimeException(
                'Attribute terms changed during sync — aborting to avoid orphan variant.'
            );
        }

        // DERIVE cartesian dimensions from REAL term groups (DB-truth). The
        // payload's group_id claims are pure display metadata — the actual
        // structure comes from term.group_id in fct_atts_terms. This means
        // a spoofed payload (e.g. claiming term 10 is in group 99 when it
        // really lives in group 5) gets remapped to its real group before
        // the cartesian runs. Same protection for the case where a payload
        // splits same-group terms across multiple "entries" or merges
        // different-group terms into one "entry": the real grouping wins.
        $realGroupedTerms = [];
        foreach ($termMap as $term) {
            $realGroupedTerms[(int) $term->group_id][(int) $term->id] = true;
        }
        $realGroups = [];
        foreach ($realGroupedTerms as $gid => $tidSet) {
            $tids = array_keys($tidSet);
            // Order each group's terms by the merchant's payload order so a
            // drag-reorder of values changes the cartesian iteration order —
            // and therefore each combination's serial_index — for THIS product
            // only. Terms with no payload rank (can't normally happen, since the
            // cartesian terms ARE the payload terms) sort to the tail in numeric
            // id order so the result stays deterministic.
            usort($tids, function ($a, $b) use ($payloadTermOrder) {
                $rankA = $payloadTermOrder[$a] ?? PHP_INT_MAX;
                $rankB = $payloadTermOrder[$b] ?? PHP_INT_MAX;
                if ($rankA === $rankB) {
                    return $a <=> $b;
                }
                return $rankA <=> $rankB;
            });
            $realGroups[$gid] = $tids;
        }
        ksort($realGroups);

        // The cartesian CONTENT is DB-derived above (anti-spoof). The cartesian
        // ORDER, though, is display metadata the merchant controls by drag-
        // reordering the option cards — ksort alone forces group-id order and
        // silently discards that choice. Re-order $realGroups to follow the
        // sequence the caller's option entries appear in: map each entry to the
        // real group of its first term, dedupe, and lead with that order. Real
        // groups no entry references (spoofed/remapped terms) keep the ksort'd
        // tail. Identifiers stay deterministic regardless — each variant's term
        // ids are asort'd numerically below, independent of group order.
        $callerGroupOrder = [];
        foreach ($variations as $variation) {
            if (empty($variation['variants']) || !is_array($variation['variants'])) {
                continue;
            }
            foreach ($variation['variants'] as $termId) {
                $term = $termMap->get((int) $termId);
                if ($term) {
                    $realGid = (int) $term->group_id;
                    if (!in_array($realGid, $callerGroupOrder, true)) {
                        $callerGroupOrder[] = $realGid;
                    }
                    break;
                }
            }
        }
        $orderedRealGroups = [];
        foreach ($callerGroupOrder as $gid) {
            if (isset($realGroups[$gid])) {
                $orderedRealGroups[$gid] = $realGroups[$gid];
            }
        }
        foreach ($realGroups as $gid => $tids) {
            if (!isset($orderedRealGroups[$gid])) {
                $orderedRealGroups[$gid] = $tids;
            }
        }
        $realGroups = $orderedRealGroups;

        // Re-cap on the DERIVED structure. The payload-time caps in
        // syncVariantOption used caller-supplied dimensions, which can
        // diverge from reality after regrouping by DB truth (e.g. a
        // payload may bundle terms from many real groups into one entry,
        // making the real group count exceed the cap even though the
        // payload entry count didn't). Re-apply both caps here.
        $maxGroups = (int) apply_filters(
            'fluent_cart/advanced_variation/max_groups_per_save',
            self::DEFAULT_MAX_GROUPS_PER_SAVE
        );
        if (count($realGroups) > $maxGroups) {
            throw new \RuntimeException(sprintf(
                'Real attribute group count (%d) exceeds the limit of %d.',
                count($realGroups),
                $maxGroups
            ));
        }
        $maxCombinations = (int) apply_filters(
            'fluent_cart/advanced_variation/max_combinations',
            self::DEFAULT_MAX_COMBINATIONS
        );
        $realProjection = 1;
        foreach ($realGroups as $tids) {
            $realProjection *= count($tids);
            if ($realProjection > PHP_INT_MAX / 1000) {
                $realProjection = PHP_INT_MAX;
                break;
            }
        }
        if ($realProjection > $maxCombinations) {
            throw new \RuntimeException(sprintf(
                'Real cartesian combination count (%d) exceeds the limit of %d.',
                $realProjection,
                $maxCombinations
            ));
        }

        // Generate the cartesian from REAL groups. Identifiers are made
        // deterministic by the per-variant asort below, independent of the
        // group order, so the caller-driven ordering above is display-only.
        $variants = self::generateVariationSets(array_values($realGroups));

        // Normalize ordering once so identifiers are deterministic across
        // requests AND so we can preload existing variations in one query
        // instead of one query per cartesian combination.
        $normalized = [];
        foreach ($variants as $index => $variant) {
            asort($variant, SORT_NUMERIC);
            $identifier = implode('_', $variant);
            // Hard guard against silent column truncation. The schema
            // constant is VARIATION_IDENTIFIER_MAX_LENGTH; without this
            // check, two distinct cartesian combinations whose joined
            // IDs differ only past char 100 would collapse to the same
            // truncated identifier on non-STRICT MySQL, creating
            // hard-to-debug duplicate variants.
            if (strlen($identifier) > self::VARIATION_IDENTIFIER_MAX_LENGTH) {
                throw new \RuntimeException(
                    'Generated variation_identifier exceeds the ' . self::VARIATION_IDENTIFIER_MAX_LENGTH
                    . '-char column limit. Reduce the number of attribute groups or contact support.'
                );
            }
            $normalized[$index] = [
                'identifier' => $identifier,
                'variant'    => $variant,
            ];
        }

        // Preload ALL existing variations for this product with their thumbnails
        // in one query. Two uses:
        //   (a) exact identifier lookup — same as before (serial_index dirty-check)
        //   (b) ancestor inheritance — when a new attribute group is added, every
        //       variation_identifier changes (new term ID appended), so the exact
        //       lookup finds nothing and all variants would be created at $0 with
        //       no media. The ancestor search below finds the old "Red / S" row as
        //       the parent of the new "Red / S / Cotton" combination and copies its
        //       price, stock, status, and thumbnail so the merchant's pricing work
        //       survives attribute expansion.
        $allExistingVariations = ProductVariation::query()
            ->where('post_id', $srcDetails->post_id)
            ->with(['media'])
            ->get();

        $existingVariations = $allExistingVariations->keyBy('variation_identifier');

        // Inverted index for the REMOVE-case ancestor search: term_id → [variants].
        // Built once in O(E) so the per-miss loop only iterates the shortest posting
        // list instead of the full collection.
        $termToVariants = [];
        foreach ($allExistingVariations as $existingVariant) {
            if (empty($existingVariant->variation_identifier)) {
                continue;
            }
            foreach (explode('_', $existingVariant->variation_identifier) as $termId) {
                $termToVariants[$termId][] = $existingVariant;
            }
        }

        // Rank each group by its position in the current (caller-ordered)
        // group sequence so composed titles follow the merchant's group order
        // (Color / Material / Size / Pattern) instead of the per-variant
        // asort, which is numeric term-id (i.e. creation-time) order. Without
        // this, reordering groups after variants exist leaves stale titles.
        $groupOrderRank = [];
        $nextGroupRank = 0;
        foreach (array_keys($realGroups) as $orderedGroupId) {
            $groupOrderRank[(int) $orderedGroupId] = $nextGroupRank++;
        }

        foreach ($normalized as $index => $row) {
            $identifier = $row['identifier'];
            $variant    = $row['variant'];

            // Compose the variant title from its terms — "Single Site / 4 GB"
            // rather than the parent product name shared by every variant.
            // Used both for new inserts below and the self-heal path on
            // already-existing variants (pre-fix data still carrying
            // post_title as their title). Ordered by group rank so the title
            // tracks the merchant's group order, not numeric term-id order.
            $termsInGroupOrder = $variant;
            usort($termsInGroupOrder, function ($leftTermId, $rightTermId) use ($termMap, $groupOrderRank) {
                $leftTerm  = $termMap->get((int) $leftTermId);
                $rightTerm = $termMap->get((int) $rightTermId);
                $leftRank  = $leftTerm ? ($groupOrderRank[(int) $leftTerm->group_id] ?? PHP_INT_MAX) : PHP_INT_MAX;
                $rightRank = $rightTerm ? ($groupOrderRank[(int) $rightTerm->group_id] ?? PHP_INT_MAX) : PHP_INT_MAX;
                return $leftRank <=> $rightRank;
            });
            $composedTitleParts = [];
            foreach ($termsInGroupOrder as $termId) {
                $term = $termMap->get((int) $termId);
                if ($term && $term->title !== '') {
                    $composedTitleParts[] = $term->title;
                }
            }
            $composedTitle = $composedTitleParts
                ? implode(' / ', $composedTitleParts)
                : $variationTitle;

            $exist          = $existingVariations->get($identifier);
            $newSerialIndex = $index + 1;
            if ($exist) {
                // Dirty-check before saving — when the cartesian shape
                // hasn't changed, every existing variant ends up with the
                // same serial_index it already has, and a blind save would
                // fire N UPDATE statements + N model events for nothing
                // (updated_at would tick even though no field changed).
                $needsSave = false;
                if ((int) $exist->serial_index !== $newSerialIndex) {
                    $exist->serial_index = $newSerialIndex;
                    $needsSave = true;
                }
                // Self-heal pre-fix rows whose title is still the parent
                // post_title. Only touch the row when the title looks like
                // an untouched legacy default — any customized title is
                // preserved. Skips the wider term-rename propagation (a
                // separate concern by design).
                if ($exist->variation_title === $variationTitle
                    && $composedTitle !== $variationTitle
                ) {
                    $exist->variation_title = $composedTitle;
                    $needsSave = true;
                } elseif ($composedTitle !== ''
                    && $exist->variation_title !== $composedTitle
                ) {
                    // Re-order auto-composed titles to follow the merchant's
                    // current group order when groups are reordered after
                    // variants exist. Only touch titles that are still the
                    // term-join in some order — a title the merchant typed has
                    // a different term multiset and is left untouched.
                    $storedTitleParts = array_map('trim', explode(' / ', (string) $exist->variation_title));
                    $sortedStoredParts   = $storedTitleParts;
                    $sortedComposedParts = $composedTitleParts;
                    sort($sortedStoredParts);
                    sort($sortedComposedParts);
                    if ($sortedStoredParts === $sortedComposedParts) {
                        $exist->variation_title = $composedTitle;
                        $needsSave = true;
                    }
                }
                // Self-heal pre-fix other_info that was written before the
                // baseline merge above existed — typically only the
                // `variant` key plus, on duplicated products, a couple of
                // setup_fee leftovers. Top up missing baseline keys
                // without disturbing whatever the merchant has customized.
                $existingOtherInfo = \is_array($exist->other_info) ? $exist->other_info : [];
                $missingKeys = array_diff_key(self::defaultVariantOtherInfo(), $existingOtherInfo);
                if (!empty($missingKeys)) {
                    $exist->other_info = array_merge($existingOtherInfo, $missingKeys);
                    $needsSave = true;
                }
                if ($needsSave) {
                    $exist->save();
                }
            } else {
                // No exact match — find the closest related variant using two
                // strategies depending on the reshape direction.
                //
                // ADD case (candidate ⊆ new, |candidate| = |new| − 1):
                //   $variant is asort()-ed, so every (k−1)-subset of its term
                //   IDs is also a valid sorted identifier. Try each of the k
                //   subsets as a direct keyed probe into $existingVariations.
                //   O(k) per miss; k ≤ 10 (MAX_GROUPS cap).
                //
                // REMOVE case (new ⊆ candidate, |candidate| > |new|):
                //   Use $termToVariants to pick the shortest posting list,
                //   then verify the full-subset condition only for those
                //   candidates. O(E / max_terms_per_group) per miss instead
                //   of O(E).
                $ancestor    = null;
                $newTermsStr = array_values(array_map('strval', $variant));
                // $variant is asort()-ed; strval preserves the numeric order.

                // ── ADD ──────────────────────────────────────────────────
                $kTerms = count($newTermsStr);
                for ($skip = 0; $skip < $kTerms; $skip++) {
                    $subTerms  = $newTermsStr;
                    array_splice($subTerms, $skip, 1);
                    $candidate = $existingVariations->get(implode('_', $subTerms));
                    if ($candidate) {
                        $ancestor = $candidate;
                        break;
                    }
                }

                // ── REMOVE ───────────────────────────────────────────────
                if ($ancestor === null) {
                    $newTermCount = $kTerms;

                    // Find the posting list with fewest entries.
                    $shortestList  = null;
                    $shortestCount = PHP_INT_MAX;
                    foreach ($newTermsStr as $tid) {
                        $list = $termToVariants[$tid] ?? [];
                        if (count($list) < $shortestCount) {
                            $shortestCount = count($list);
                            $shortestList  = $list;
                        }
                    }

                    if ($shortestList !== null) {
                        $newTermFlip = array_flip($newTermsStr);
                        $bestExtra   = PHP_INT_MAX;
                        foreach ($shortestList as $candidate) {
                            $candidateTerms = explode('_', $candidate->variation_identifier);
                            $candidateCount = count($candidateTerms);
                            if ($candidateCount <= $newTermCount) {
                                continue; // must be a strict superset
                            }
                            $extraCount = $candidateCount - $newTermCount;
                            if ($extraCount >= $bestExtra) {
                                continue; // tighter fit already found
                            }
                            $candidateFlip = array_flip($candidateTerms);
                            $allIn         = true;
                            foreach ($newTermsStr as $tid) {
                                if (!isset($candidateFlip[$tid])) {
                                    $allIn = false;
                                    break;
                                }
                            }
                            if ($allIn) {
                                $ancestor  = $candidate;
                                $bestExtra = $extraCount;
                            }
                        }
                        unset($newTermFlip);
                    }
                }

                // Layer other_info so the new variant carries the same
                // baseline simple variations get, the ancestor's
                // customizations (payment_type, tax_class, signup_fee, …)
                // if reshaping the cartesian, and finally the term IDs
                // for this row. Without the baseline, every downstream
                // reader that asks for other_info.payment_type would get
                // NULL and break the OrderItem insert.
                $ancestorOtherInfo = ($ancestor !== null && \is_array($ancestor->other_info))
                    ? $ancestor->other_info
                    : [];
                $composedOtherInfo = array_merge(
                    self::defaultVariantOtherInfo(),
                    $ancestorOtherInfo,
                    ['variant' => array_values($variant)]
                );

                $exist = ProductVariation::create([
                    'post_id'              => $srcDetails->post_id,
                    'serial_index'         => $newSerialIndex,
                    'item_price'           => $ancestor !== null ? (float) $ancestor->item_price   : 0,
                    'compare_price'        => $ancestor !== null ? (float) $ancestor->compare_price : 0,
                    // New combinations (no ancestor to inherit from) default to
                    // the same starter stock a Simple product's default variant
                    // gets (ProductController::store): in-stock, total_stock 1,
                    // available 1. manage_stock defaults to 0, so these values
                    // only matter once the merchant turns stock management on —
                    // but defaulting to 1 keeps them consistent with simple
                    // variations instead of starting out-of-stock-on-enable.
                    'total_stock'          => $ancestor !== null ? (int)   $ancestor->total_stock   : 1,
                    'available'            => $ancestor !== null ? (int)   $ancestor->available     : 1,
                    'stock_status'         => $ancestor !== null ? $ancestor->stock_status          : 'in-stock',
                    'item_status'          => $ancestor !== null ? $ancestor->item_status           : 'active',
                    'fulfillment_type'     => $ancestor !== null ? $ancestor->fulfillment_type      : $srcDetails->fulfillment_type,
                    'manage_stock'         => $ancestor !== null ? $ancestor->manage_stock          : $srcDetails->manage_stock,
                    // Advanced-variation missed the payment_type column that
                    // simple / simple_variations set — default it to 'onetime'.
                    'payment_type'         => Arr::get($composedOtherInfo, 'payment_type', 'onetime'),
                    'variation_title'      => $composedTitle,
                    'variation_identifier' => $identifier,
                    'other_info'           => $composedOtherInfo,
                ]);

                // Copy thumbnail from ancestor if present. Each variant owns
                // its own ProductMeta row; create() stamps object_type correctly.
                if ($ancestor !== null
                    && $ancestor->media
                    && is_array($ancestor->media->meta_value)
                ) {
                    ProductMetaResource::create($ancestor->media->meta_value, ['product_id' => $exist->id]);
                }
            }

            $variantIds[] = $exist->id;
            // Stash the resolved variant id back into the row so the
            // relations pass below doesn't have to re-resolve.
            $normalized[$index]['variant_id'] = $exist->id;
        }

        // Bulk relations: preload every relation row for these variants in
        // ONE query, compute which (variant_id, term_id) pairs are missing,
        // insert just those in a single statement. The old per-term
        // firstOrCreate ran 2 queries × N terms × M variants — at 5 groups
        // × 4 terms × 10 variants that was 400 queries; this is 2.
        $existingKeys = [];
        if ($variantIds) {
            $existingRelations = AttributeRelation::query()
                ->whereIn('object_id', $variantIds)
                ->get();
            foreach ($existingRelations as $rel) {
                $existingKeys[$rel->object_id . ':' . $rel->term_id] = true;
            }
        }

        // Bulk insert() bypasses Eloquent auto-timestamps, so stamp them
        // explicitly. Schema is `created_at DATETIME NULL` but every other
        // table in the codebase has stamped timestamps for forensics.
        $now = gmdate('Y-m-d H:i:s');
        $relationRows = [];
        foreach ($normalized as $row) {
            $variantId = $row['variant_id'];
            foreach ($row['variant'] as $termId) {
                $term = $termMap->get($termId);
                if (!$term) {
                    continue;
                }
                $key = $variantId . ':' . $termId;
                if (isset($existingKeys[$key])) {
                    continue;
                }
                $relationRows[] = [
                    'object_id'  => $variantId,
                    'term_id'    => (int) $termId,
                    'group_id'   => (int) $term->group_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                // Mark in-memory so a duplicate row within this batch
                // (shouldn't happen, but cartesian + bad input could)
                // doesn't trip the composite UNIQUE.
                $existingKeys[$key] = true;
            }
        }

        if ($relationRows && !AttributeRelation::insert($relationRows)) {
            throw new \RuntimeException('AttributeRelation::insert returned false during variant sync.');
        }

        // Permanently deletes variant rows (stock, pricing) not in $variantIds — removing an attribute from the config is irreversible.
        ProductAdminHelper::deleteOrphanVariant($srcDetails->post_id, $variantIds);

        // Build the DB-derived attribute_config to hand back for storage —
        // one entry per real group (caller-ordered above) with deduped term
        // IDs. The shape matches what sanitizeSettings produced for the
        // happy path (group_id + variants), so the editor reading back
        // sees a familiar structure, just normalized.
        $attributeConfig = [];
        foreach ($realGroups as $gid => $tids) {
            $attributeConfig[] = [
                'group_id' => $gid,
                'variants' => $tids,
            ];
        }

        // Eager-load media + attrMap so the POST response carries everything
        // the admin table needs to render immediately. The editor optimistically
        // swaps these variants into the reactive store and holds its skeleton
        // until a variant has an attr_map (so the grouped table can bucket rows
        // instead of flashing the flat fallback). Without attrMap here that
        // condition is only met after the fire-and-forget reloader() round-trip,
        // so the skeleton lingered up to its 2.5s timeout after the save already
        // succeeded. Loading the relation lets the skeleton clear in the same
        // frame as the optimistic swap.
        return [
            // Order by serial_index so the POST response the editor optimistically
            // swaps in already matches the merchant's term/group order — same
            // ordering the variants() relation (ProductDetail / Product) applies on
            // the reloader round-trip, so the table doesn't flash id-order first.
            'variants'         => ProductVariation::query()
                ->whereIn('id', $variantIds)
                ->with(['media', 'attrMap'])
                ->orderBy('serial_index', 'asc')
                ->get(),
            'attribute_config' => $attributeConfig,
        ];
    }

    /**
     * Whitelist-sanitize the caller-supplied options payload. Strips any
     * keys we don't explicitly recognise so a hostile payload cannot pollute
     * ProductDetail.other_info.attribute_co
     * nfig with arbitrary structure.
     * Within each group entry, only 'variants' (term IDs) and 'group_id'
     * survive — both coerced to ints. Empty / non-array inputs become [].
     *
     * Runs BEFORE the cap checks so they operate on the cleaned shape,
     * and BEFORE the storage write so what lands in other_info matches
     * what the rest of the codebase reads back.
     */
    private static function sanitizeSettings($settings): array
    {
        if (!is_array($settings)) {
            return [];
        }

        // First pass — normalize each entry to {variants: int[], group_id?: int}.
        $entries = [];
        foreach ($settings as $variation) {
            if (!is_array($variation)) {
                continue;
            }
            $entry = [];
            if (isset($variation['variants']) && is_array($variation['variants'])) {
                $ids = [];
                foreach ($variation['variants'] as $termId) {
                    $id = (int) $termId;
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
                $entry['variants'] = array_values(array_unique($ids));
            }
            if (isset($variation['group_id'])) {
                $gid = (int) $variation['group_id'];
                // Drop zero/negative — group_id is just metadata for round-trip
                // display, but a 0/-N value passing through into other_info
                // would land as a garbage key in the stored attribute_config.
                if ($gid > 0) {
                    $entry['group_id'] = $gid;
                }
            }
            // Skip entries with no usable terms — the cartesian would
            // ignore them anyway, no point polluting the stored config.
            if (!empty($entry['variants'])) {
                $entries[] = $entry;
            }
        }

        // Second pass — merge entries that share the same group_id. A payload
        // like [{group_id:5, variants:[10]}, {group_id:5, variants:[20]}]
        // describes ONE attribute group with two selected terms (10 AND 20),
        // not two separate group dimensions in the cartesian. Without this
        // merge, the cartesian generates variants with TWO terms from the
        // same group each — a single variant claiming to be both "Color:Red"
        // AND "Color:Blue" simultaneously. AttributeRelation has a composite
        // UNIQUE on (object_id, group_id, term_id) that lets both rows in,
        // so the storage corruption is silent. Same pattern the bot hit on
        // round 9 for term-within-group dedup — one level up.
        $byGroupId = [];
        $ungrouped = [];
        foreach ($entries as $entry) {
            if (isset($entry['group_id'])) {
                $gid = $entry['group_id'];
                if (isset($byGroupId[$gid])) {
                    $byGroupId[$gid]['variants'] = array_values(array_unique(
                        array_merge($byGroupId[$gid]['variants'], $entry['variants'])
                    ));
                } else {
                    $byGroupId[$gid] = $entry;
                }
            } else {
                // Entries without group_id can't be deduped by it — pass
                // through as distinct cartesian dimensions. A well-formed
                // editor payload always includes group_id; missing-group_id
                // is a defensive case for older client builds.
                $ungrouped[] = $entry;
            }
        }

        return array_merge(array_values($byGroupId), $ungrouped);
    }

    /**
     * Returns the term IDs from the payload that don't exist in fct_atts_terms.
     * Called by syncVariantOption to reject the whole request when ANY term
     * reference is unknown — better than silently creating orphan variants
     * with missing relation rows (the bot-flagged failure mode).
     *
     * Returns empty array when every referenced term resolves. The single
     * whereIn query is bounded by the projected-combination cap above, so
     * worst case is one indexed PK lookup of <= MAX_COMBINATIONS unique IDs.
     */
    private static function findMissingTermIds(array $variations): array
    {
        $termIds = [];
        foreach ($variations as $variation) {
            $variants = Arr::get($variation, 'variants', []);
            if (!is_array($variants)) {
                continue;
            }
            foreach ($variants as $termId) {
                $id = (int) $termId;
                if ($id > 0) {
                    $termIds[] = $id;
                }
            }
        }
        $termIds = array_values(array_unique($termIds));

        if (empty($termIds)) {
            return [];
        }

        $found = AttributeTerm::query()
            ->whereIn('id', $termIds)
            ->pluck('id')
            ->map('intval')
            ->all();

        return array_values(array_diff($termIds, $found));
    }

    /**
     * Number of non-empty groups in the payload. Empty groups are skipped
     * because they neither contribute to the cartesian nor to the cost
     * caps below. Bounds the depth of the cartesian AND the length of the
     * variation_identifier string (joined term IDs per variant).
     */
    private static function countNonEmptyGroups(array $variations): int
    {
        $count = 0;
        foreach ($variations as $variation) {
            $terms = Arr::get($variation, 'variants', []);
            if (is_array($terms) && !empty($terms)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Total unique term IDs referenced across all groups (positive ints
     * only). Bounds the size of the term-validation whereIn and the
     * per-save relation-insert volume — independently of the combination
     * cap, which can be satisfied even with many single-term groups.
     */
    private static function countUniqueTermIds(array $variations): int
    {
        $ids = [];
        foreach ($variations as $variation) {
            $terms = Arr::get($variation, 'variants', []);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $termId) {
                $id = (int) $termId;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }
        return count($ids);
    }

    /**
     * Cheap O(N) projection of the cartesian combination count from the raw
     * payload — multiplies the term count of every non-empty group. Used by
     * the cap guard in syncVariantOption so we reject blow-up payloads
     * BEFORE generateVariationSets() materialises the actual combinations.
     *
     * Returns 0 when every group is empty (no variants will be generated)
     * so the cap check treats that as a no-op, not a violation.
     */
    private static function projectCombinationCount(array $variations): int
    {
        $product = 1;
        $sawAny  = false;
        foreach ($variations as $variation) {
            $terms = Arr::get($variation, 'variants', []);
            if (!is_array($terms) || empty($terms)) {
                continue;
            }
            $sawAny = true;
            $product *= count($terms);
            // Bail early once we've already blown past any reasonable cap —
            // no need to keep multiplying once we're in the tens of millions.
            if ($product > PHP_INT_MAX / 1000) {
                return PHP_INT_MAX;
            }
        }
        return $sawAny ? $product : 0;
    }

    private static function generateVariationSets(array $groups): array
    {
        if (empty($groups)) {
            return [];
        }

        $result = [[]];

        foreach ($groups as $group) {
            $expanded = [];
            foreach ($result as $existing) {
                foreach ($group as $item) {
                    $expanded[] = array_merge($existing, [$item]);
                }
            }
            $result = $expanded;
        }

        return $result;
    }

    /**
     * Attaches term + group display fields to every variant.attr_map row on
     * the given product payload. Runs once per single-product API response
     * (scoped by the variation_type guard in AdvancedVariation::register).
     * Eager-loads terms with their group in ONE query so a product with N
     * variants is still one query, not N.
     *
     * Returns the original product array unchanged when no terms are
     * referenced — list-endpoint responses or products with no advanced
     * variation pay no query cost.
     */
    public static function hydrateProductData(array $product): array
    {
        $variants = Arr::get($product, 'variants', []);

        $termIds = [];
        foreach ($variants as $variant) {
            foreach (Arr::get($variant, 'attr_map', []) as $relation) {
                $termId = (int) Arr::get($relation, 'term_id');
                if ($termId) {
                    $termIds[] = $termId;
                }
            }
        }
        $termIds = array_unique($termIds);

        if (empty($termIds)) {
            return $product;
        }

        $terms = AttributeTerm::query()
            ->whereIn('id', $termIds)
            ->with('group')
            ->get()
            ->keyBy('id');

        // Rank groups by the merchant's current order (other_info.attribute_config)
        // so the storefront selector / labels follow the same Color / Material /
        // Size / Pattern order as the editor — not the relation insertion order,
        // which is frozen at creation time and ignores later drag-drop reorders.
        $groupOrderRank = [];
        $nextGroupRank = 0;
        foreach (Arr::get($product, 'detail.other_info.attribute_config', []) as $groupConfig) {
            $groupId = (int) Arr::get($groupConfig, 'group_id');
            if ($groupId && !isset($groupOrderRank[$groupId])) {
                $groupOrderRank[$groupId] = $nextGroupRank++;
            }
        }

        foreach ($variants as $index => $variant) {
            $mapped = [];
            foreach (Arr::get($variant, 'attr_map', []) as $relation) {
                $termId = (int) Arr::get($relation, 'term_id');
                $term   = $terms->get($termId);
                if (!$term) {
                    continue;
                }
                $mapped[] = [
                    'term_id'    => $termId,
                    'title'      => $term->title,
                    'slug'       => $term->slug,
                    'group_id'   => $term->group_id,
                    'group_title' => $term->group ? $term->group->title : '',
                    'group_slug'  => $term->group ? $term->group->slug : '',
                ];
            }
            if ($groupOrderRank) {
                usort($mapped, function ($leftAttr, $rightAttr) use ($groupOrderRank) {
                    $leftRank  = $groupOrderRank[(int) $leftAttr['group_id']] ?? PHP_INT_MAX;
                    $rightRank = $groupOrderRank[(int) $rightAttr['group_id']] ?? PHP_INT_MAX;
                    return $leftRank <=> $rightRank;
                });
            }
            $product['variants'][$index]['attr_map'] = $mapped;
        }

        return $product;
    }
}
