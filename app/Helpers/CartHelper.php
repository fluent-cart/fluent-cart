<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\ShippingClass;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Services\CheckoutService;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Support\Arr;

class CartHelper
{
    public static function getCart($hash = null, $create = false)
    {
        return CartResource::get([
            'hash'   => $hash ?? App::request()->get(Helper::INSTANT_CHECKOUT_URL_PARAM),
            'create' => $create
        ]);
    }

    public static function generateCartItemFromVariation(ProductVariation $variation, $quantity = 1): array
    {
        $mediaUrl = $variation->thumbnail ?: $variation->product->thumbnail;

        //  $shippingCharge = static::calculateShippingCharge($variation, $quantity);

        $itemPrice = apply_filters('fluent_cart/cart/item_price', $variation->item_price, [
            'variation' => $variation,
            'quantity'  => $quantity,
        ]);

        // Ensure filtered price is a valid non-negative integer (cents)
        $itemPrice = max(0, (int)$itemPrice);

        $subtotal = $itemPrice * $quantity;

        //Need to test and check this toArray Issue
        $data = wp_parse_args([
            'quantity'             => $quantity,
            'price'                => $itemPrice,
            'unit_price'           => $itemPrice,
            'line_total'           => $itemPrice * $quantity,
            'subtotal'             => $subtotal,
            'discount_total'       => 0,
            'tax_total'            => 0,
            'line_total_formatted' => CurrencySettings::getFormattedPrice($subtotal),
            'object_id'            => $variation->id,
            'title'                => $variation->variation_title,
            'post_title'           => $variation->product->post_title,
            'coupon_discount'      => 0,
            'cost'                 => $variation->item_cost ?? 0,
            'featured_media'       => $mediaUrl,
            'view_url'             => URL::appendQueryParams(
                $variation->product->view_url,
                [
                    'selected' => $variation->id
                ]
            ),
            // Property access lazy-loads when callers didn't eager-load
            // product_detail (instant-checkout, cart update endpoint) so
            // CartRenderer can read variation_type to toggle the
            // variant-title-hidden class on simple products.
            'variation_type'       => $variation->product_detail ? $variation->product_detail->variation_type : '',
            'is_custom'            => false,
        ], $variation->toArray());

        $cartItem = Arr::only($data, [
            'id',
            'object_id',
            'post_id',
            'quantity',
            'post_title',
            'title',
            'price',
            'unit_price',
            'coupon_discount',
            'fulfillment_type',
            'featured_media',
            'other_info',
            'cost',
            'view_url',
            'line_total_formatted',
            'line_total',
            'subtotal',
            'total',
            'variation_type',
            'is_custom'
        ]);

        //  $cartItem['shipping_charge'] = $shippingCharge;

        // Snapshot the variant's attribute set (pa_* + third-party) into
        // other_info so cart, checkout and the resulting order item all carry a
        // frozen attribute map that survives later attribute-library renames.
        $otherInfo = Arr::get($cartItem, 'other_info', []);
        if (!is_array($otherInfo)) {
            $otherInfo = [];
        }
        $otherInfo['item_attributes'] = AttributeHelper::getProductItemAttributes(
            $variation->id,
            $variation->post_id
        );
        $cartItem['other_info'] = $otherInfo;

        return $cartItem;
    }

    public static function generateCartItemCustomItem(array $variation, $quantity = 1): array
    {
        //Need to test and check this toArray Issue
        $data = wp_parse_args(
            [
                'quantity'       => $quantity,
                'price'          => Arr::get($variation, 'item_price'),
                'unit_price'     => Arr::get($variation, 'item_price'),
                'object_id'      => Arr::get($variation, 'id'),
                'tax_amount'     => Arr::get($variation, 'tax_amount', 0),
                'title'          => Arr::get($variation, 'variation_title'),
                'post_title'     => Arr::get($variation, 'post_title'),
                'cost'           => Arr::get($variation, 'item_cost', 0),
                'featured_media' => Arr::get($variation, 'featured_media'),
                'view_url'       => Arr::get($variation, 'view_url'),
                'variation_type' => Arr::get($variation, 'variation_type'),
                'is_custom'      => Arr::get($variation, 'is_custom', false),
            ],
            $variation
        );

        $manualDiscount = Arr::get($data, 'manual_discount', 0);
        $couponDiscount = Arr::get($data, 'coupon_discount', 0);
        $discountTotal = $manualDiscount + $couponDiscount;
        $itemPrice = Arr::get($data, 'item_price', 0);
        if (!is_numeric($itemPrice)) {
            $itemPrice = 0;
        }
        $subtotal = $itemPrice * Arr::get($data, 'quantity');

        $data['subtotal'] = $subtotal;
        $data['manual_discount'] = $manualDiscount;
        $data['coupon_discount'] = $couponDiscount;
        $data['discount_total'] = $discountTotal;
        $data['line_total'] = $subtotal - $discountTotal;

        $cartItem = Arr::only($data, [
            'id',
            'object_id',
            'post_id',
            'quantity',
            'post_title',
            'title',
            'price',
            'unit_price',
            'manual_discount',
            'coupon_discount',
            'discount_total',
            'fulfillment_type',
            'featured_media',
            'other_info',
            'cost',
            'view_url',
            'line_total',
            'subtotal',
            'total',
            'variation_type',
            'is_custom'
        ]);

        return $cartItem;
    }

    public static function calculateShippingCharge(ProductVariation $variation, int $quantity = 1)
    {

        if ($variation->fulfillment_type !== 'physical') {
            return 0;
        }

        $shippingClass = $variation->shippingClass;

        if (!$shippingClass) {
            return 0;
        }

        $factor = empty($shippingClass->per_item) ? 1 : $quantity;
        if ($shippingClass->type === 'percentage') {
            return ($shippingClass->cost / 100) * $variation->item_price * $factor;
        }

        return ($shippingClass->cost * 100) * $factor;
    }

    private static function itemHasFreeShipping($item): bool
    {
        return Arr::get($item, 'other_info.free_shipping', 'no') === 'yes';
    }

    private static function excludeFreeShippingPhysicalItems(array &$items, array &$physicalItems): void
    {
        foreach ($physicalItems as $key => $item) {
            if (self::itemHasFreeShipping($item)) {
                $items[$key]['shipping_charge'] = 0;
                $items[$key]['itemwise_shipping_charge'] = 0;
                unset($physicalItems[$key]);
            }
        }
    }

    public static function calculateShippingMethodCharge(ShippingMethod $method, ?array $items = null, $returnType = 'amount')
    {
        static $onceCalculated = false;
        static $lastFingerprint = null;
        static $products = null;
        static $shippingClasses = null;

        // Per-call locals: $physicalItems/$isAllDigital are rebuilt fresh from $items on every
        // call (via CheckoutService below), and $totalItemPrice/$totalQuantity/
        // $totalShippingCharge/$maxShippingCharge are accumulated fresh in the per-item
        // annotation loop below, so none of them may persist across calls — only the
        // $products/$shippingClasses DB lookups above are worth caching per request.
        $totalItemPrice = 0;
        $totalQuantity = 0;
        $physicalItems = [];
        $isAllDigital = false;
        $maxShippingCharge = 0;
        $totalShippingCharge = 0;
        $isUsingCart = false;

        if ($items === null) {
            $isUsingCart = true;
            $items = static::getCart()->cart_data ?? [];
        }

        // Fingerprint the resolved method + items so a same-request call with changed
        // cart items (e.g. an item added/removed after ShippingModule::handleItemsChanges
        // re-runs this calc) is never mistaken for a repeat of the previous call. Must be
        // computed from the RESOLVED $items (post null → cart fallback above), not the raw
        // argument, otherwise a null-argument call would fingerprint differently from the
        // cart data it resolves to. Fields: id/object_id/variation_id, quantity, line_total,
        // free_shipping, post_id, unit_price, discount_total.
        $fingerprint = md5(serialize([
            $method->id,
            array_map(function ($item) {
                return [
                    Arr::get($item, 'id', Arr::get($item, 'object_id', Arr::get($item, 'variation_id'))),
                    Arr::get($item, 'quantity'),
                    Arr::get($item, 'line_total'),
                    self::itemHasFreeShipping($item) ? 'yes' : 'no',
                    Arr::get($item, 'post_id'),
                    Arr::get($item, 'unit_price'),
                    Arr::get($item, 'discount_total'),
                ];
            }, $items),
        ]));

        // Reset the cached-lookup guard when the method/items fingerprint changes to prevent
        // stale $products/$shippingClasses from a previous call in the same request (replaces
        // the old $lastMethodId check, which missed same-method-id calls made with different
        // items). The per-call locals above are already reinitialized on every call, so only
        // the "once" guard needs resetting here.
        if ($lastFingerprint !== $fingerprint) {
            $onceCalculated = false;
            $lastFingerprint = $fingerprint;
        }

        if ($method->type === 'free_shipping') {
            if ($returnType === 'items') {
                if ($items === null) {
                    $items = static::getCart()->cart_data ?? [];
                }
                foreach ($items as $key => $item) {
                    $items[$key]['shipping_charge'] = 0;
                    $items[$key]['itemwise_shipping_charge'] = 0;
                }
                return [
                    'items'           => $items,
                    'shipping_amount' => 0
                ];
            }
            return 0;
        }

        $cartCheckoutService = new CheckoutService($items);
        $isAllDigital = $cartCheckoutService->isAllDigital();
        $physicalItems = $cartCheckoutService->physicalItems;

        // Exclude only physical items marked for free shipping from charge calculation.
        static::excludeFreeShippingPhysicalItems($items, $physicalItems);

        // No shipping is charged for all-digital carts or when every physical item has free shipping.
        if ($isAllDigital || empty($physicalItems)) {
            if ($returnType === 'items') {
                foreach ($items as $key => $item) {
                    $items[$key]['shipping_charge'] = 0;
                    $items[$key]['itemwise_shipping_charge'] = 0;
                }
                return [
                    'items'           => $items,
                    'shipping_amount' => 0
                ];
            }
            return 0;
        }

        if (!$onceCalculated) {
            $onceCalculated = true;
            $productIds = array_unique(array_column($physicalItems, 'post_id'));
            $products = Product::query()->whereIn('ID', $productIds)
                ->with(['detail'])
                ->get()
                ->keyBy('ID');

            $shippingClassIds = $products->pluck('detail.other_info.shipping_class')->filter(function ($item) {
                return !empty($item);
            })->toArray();

            $shippingClasses = ShippingClass::query()->whereIn('id', $shippingClassIds)->get()->keyBy('id');
        }

        // Per-item annotation must run on every call, not gated behind $onceCalculated:
        // $physicalItems is always re-derived fresh from the current $items argument above, so
        // a cache-hit call still needs its own $items populated with shipping_charge and its
        // own totals accumulated. Only the $products/$shippingClasses DB lookups above are
        // safe to reuse across calls in the same request.
        foreach ($physicalItems as $key => &$item) {
            $totalQuantity += Arr::get($item, 'quantity');
            $totalItemPrice += (Arr::get($item, 'quantity') * Arr::get($item, 'unit_price')) - Arr::get($item, 'discount_total');
            $itemShippingCharge = 0;

            $product = $products->get(Arr::get($item, 'post_id'));


            if (isset($product->detail->other_info['shipping_class'])) {
                // shipping_class is null or not defined
                $shippingClass = $shippingClasses->get(
                    $product->detail->other_info['shipping_class']
                );

                if ($shippingClass) {
                    $perItem = $shippingClass->per_item;
                    $factor = empty($perItem) ? 1 : Arr::get($item, 'quantity');
                    if ($shippingClass->type === 'percentage') {
                        $itemShippingCharge = ($shippingClass->cost / 100) * Arr::get($item, 'unit_price') * $factor;
                    } else {
                        $itemShippingCharge = Helper::toCent($shippingClass->cost) * $factor;
                    }
                }
            }
            $item['shipping_charge'] = $itemShippingCharge;
            $totalShippingCharge += $itemShippingCharge;

            $items[$key] = $item;
            $maxShippingCharge = max($maxShippingCharge, $itemShippingCharge);
        }
        unset($item);

        $settings = Arr::wrap($method->settings);
        $configureRate = Arr::get($settings, 'configure_rate', 'per_order');
        $classAggregation = Arr::get($settings, 'class_aggregation', 'sum_all');

        if ($configureRate === 'per_order') {
            $shippingMethodAmount = $method->amount * 100;
        } else if ($configureRate === 'per_price') {
            $shippingMethodAmount = $totalItemPrice * ($method->amount / 100);
        } else if ($configureRate === 'per_weight') {
            // Sum (product weight + package weight) * quantity for all physical items
            $storeWeightUnit = Helper::shopConfig('weight_unit') ?: 'kg';
            $totalWeight = 0;

            // Batch-load all variations to avoid N+1
            $variationIds = array_filter(array_map(function ($item) {
                return Arr::get($item, 'object_id', Arr::get($item, 'variation_id'));
            }, $physicalItems));
            $variationsMap = $variationIds ? ProductVariation::query()->whereIn('id', $variationIds)->get()->keyBy('id') : new \FluentCart\Framework\Support\Collection();

            foreach ($physicalItems as $item) {
                $variationId = Arr::get($item, 'object_id', Arr::get($item, 'variation_id'));
                if ($variationId) {
                    $variation = $variationsMap->get($variationId);
                    if ($variation) {
                        $otherInfo = $variation->other_info ?: [];
                        $productWeight = floatval(Arr::get($otherInfo, 'weight', 0));
                        $productWeightUnit = Arr::get($otherInfo, 'weight_unit', $storeWeightUnit);

                        // Convert product weight to store unit
                        $convertedProductWeight = Helper::convertWeight($productWeight, $productWeightUnit, $storeWeightUnit);

                        // Add package weight
                        $packageSlug = Arr::get($otherInfo, 'package_slug', '');
                        $package = Helper::getPackageBySlug($packageSlug);
                        $packageWeight = 0;
                        if ($package) {
                            $packageWeightUnit = Arr::get($package, 'weight_unit', $storeWeightUnit);
                            $packageWeight = Helper::convertWeight(
                                floatval(Arr::get($package, 'weight', 0)),
                                $packageWeightUnit,
                                $storeWeightUnit
                            );
                        }

                        $totalWeight += ($convertedProductWeight + $packageWeight) * Arr::get($item, 'quantity', 1);
                    }
                }
            }

            // Look up matching tier from weight_tiers
            $weightTiers = Arr::get($settings, 'weight_tiers', []);
            $shippingMethodAmount = 0;
            foreach ($weightTiers as $tier) {
                $min = floatval(Arr::get($tier, 'min', 0));
                $max = floatval(Arr::get($tier, 'max', 0));
                $cost = floatval(Arr::get($tier, 'cost', 0));

                if ($totalWeight >= $min && ($max <= 0 || $totalWeight <= $max)) {
                    $shippingMethodAmount = Helper::toCent($cost);
                    break;
                }
            }
        } else {
            $shippingMethodAmount = $method->amount * $totalQuantity * 100;
        }

        if ($classAggregation === 'highest_class') {
            $shippingMethodAmount += $maxShippingCharge;
        } else {
            $shippingMethodAmount += $totalShippingCharge;
        }

        $shippingMethodAmount = (int)round($shippingMethodAmount);

        $remainingShippingMethodAmount = ($shippingMethodAmount - $totalShippingCharge);

        // Distribution must run on every call (not gated behind a "once" flag): $physicalItems
        // above is always re-derived fresh from the current $items argument regardless of the
        // $onceCalculated cache, so a cached call still needs its own $items populated with
        // itemwise_shipping_charge — a stale "already distributed" flag would leave a freshly
        // passed-in items array with missing/zero shares even though the fingerprint matched.
        $totalLineTotal = array_sum(array_column($physicalItems, 'line_total'));
        $distributed = 0;
        $itemCount = count($physicalItems);
        $lastIndex = array_key_last($physicalItems);

        foreach ($physicalItems as $key => $item) {
            if ($key === $lastIndex) {
                // Last item takes the exact remainder — per-item rounding must never
                // change the total the customer is charged for shipping.
                $share = (int) round($remainingShippingMethodAmount - $distributed);
            } elseif ($totalLineTotal > 0) {
                $share = (int) round(($item['line_total'] / $totalLineTotal) * $remainingShippingMethodAmount);
            } else {
                $share = (int) round($remainingShippingMethodAmount / $itemCount);
            }
            $items[$key]['itemwise_shipping_charge'] = $share;
            $distributed += $share;
        }

        if ($isUsingCart) {
            $cart = CartHelper::getCart();
            $cart->cart_data = $items;
            $cart->save();

            do_action('fluent_cart/checkout/shipping_data_changed', [
                'cart' => $cart
            ]);
        }

        if ($returnType === 'items') {
            return [
                'items'           => $items,
                'shipping_amount' => $shippingMethodAmount
            ];
        }

        return $shippingMethodAmount;
    }

    /**
     * Calculate shipping charges using the profile-based approach.
     * Groups cart items by shipping class, finds applicable methods per profile,
     * falls back to General zones when no class-specific zones exist.
     *
     * @param int $shippingMethodId The selected shipping method ID
     * @param array $cartItems Cart items
     * @param string $country Country code
     * @param string|null $state State code
     * @param string $returnType 'amount' or 'items'
     * @return int|array
     */
    public static function calculateShippingByProfile($shippingMethodId, $cartItems, $country, $state = null, $returnType = 'amount')
    {
        $cartCheckoutService = new CheckoutService($cartItems);
        $isAllDigital = $cartCheckoutService->isAllDigital();
        $physicalItems = $cartCheckoutService->physicalItems;

        // Exclude only physical items marked for free shipping from profile-based charges.
        static::excludeFreeShippingPhysicalItems($cartItems, $physicalItems);

        // No shipping is charged for all-digital carts or when every physical item has free shipping.
        if ($isAllDigital || empty($physicalItems)) {
            if ($returnType === 'items') {
                foreach ($cartItems as $key => $item) {
                    $cartItems[$key]['shipping_charge'] = 0;
                    $cartItems[$key]['itemwise_shipping_charge'] = 0;
                }
                return ['items' => $cartItems, 'shipping_amount' => 0];
            }
            return 0;
        }

        // Load products with details
        $productIds = array_unique(array_column($physicalItems, 'post_id'));
        $products = Product::query()->whereIn('ID', $productIds)
            ->with(['detail'])
            ->get()
            ->keyBy('ID');

        // Group physical items by shipping_class_id (null = General group)
        $groups = [];
        foreach ($physicalItems as $key => $item) {
            $product = $products->get(Arr::get($item, 'post_id'));
            $classId = null;
            if ($product && isset($product->detail->other_info['shipping_class'])) {
                $classId = $product->detail->other_info['shipping_class'] ?: null;
            }
            $groupKey = $classId ?: 'general';
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'class_id' => $classId,
                    'items'    => [],
                    'keys'     => []
                ];
            }
            $groups[$groupKey]['items'][] = $item;
            $groups[$groupKey]['keys'][] = $key;
        }

        // Load shipping classes for surcharge calculation
        $classIds = array_filter(array_column($groups, 'class_id'));
        $shippingClasses = !empty($classIds)
            ? ShippingClass::query()->whereIn('id', $classIds)->get()->keyBy('id')
            : new \FluentCart\Framework\Support\Collection();

        $selectedMethod = ShippingMethod::find($shippingMethodId);
        if (!$selectedMethod) {
            if ($returnType === 'items') {
                return ['items' => $cartItems, 'shipping_amount' => 0];
            }
            return 0;
        }

        // Early return for free_shipping — no base rate, no class surcharges
        if ($selectedMethod->type === 'free_shipping') {
            if ($returnType === 'items') {
                foreach ($cartItems as $key => $item) {
                    $cartItems[$key]['shipping_charge'] = 0;
                    $cartItems[$key]['itemwise_shipping_charge'] = 0;
                }
                return ['items' => $cartItems, 'shipping_amount' => 0];
            }
            return 0;
        }

        $totalShippingAmount = 0;

        // Preload all variations for weight calculation (avoids N+1 per group)
        $allVariationIds = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $vId = Arr::get($item, 'object_id', Arr::get($item, 'variation_id'));
                if ($vId) $allVariationIds[] = $vId;
            }
        }
        $allVariationIds = array_unique(array_filter($allVariationIds));
        $allVariationsMap = !empty($allVariationIds)
            ? ProductVariation::query()->whereIn('id', $allVariationIds)->get()->keyBy('id')
            : new \FluentCart\Framework\Support\Collection();

        // Compute cart-wide totals BEFORE the group loop (for per_order/per_price/per_weight base rate)
        $cartTotalPrice = 0;
        $cartTotalQuantity = 0;
        foreach ($physicalItems as $item) {
            $quantity = Arr::get($item, 'quantity', 1);
            $cartTotalQuantity += $quantity;
            $cartTotalPrice += ($quantity * Arr::get($item, 'unit_price', 0)) - Arr::get($item, 'discount_total', 0);
        }

        // Calculate the method base rate ONCE using cart-wide totals
        $settings = Arr::wrap($selectedMethod->settings);
        $configureRate = Arr::get($settings, 'configure_rate', 'per_order');
        $classAggregation = Arr::get($settings, 'class_aggregation', 'sum_all');

        if ($configureRate === 'per_order') {
            $methodBaseRate = Helper::toCent($selectedMethod->amount);
        } elseif ($configureRate === 'per_price') {
            $methodBaseRate = $cartTotalPrice * ($selectedMethod->amount / 100);
        } elseif ($configureRate === 'per_weight') {
            $storeWeightUnit = Helper::shopConfig('weight_unit') ?: 'kg';
            $totalWeight = 0;

            foreach ($physicalItems as $item) {
                $varId = Arr::get($item, 'object_id', Arr::get($item, 'variation_id'));
                $variation = $varId ? $allVariationsMap->get($varId) : null;
                if ($variation) {
                    $otherInfo = $variation->other_info ?: [];
                    $productWeight = floatval(Arr::get($otherInfo, 'weight', 0));
                    $productWeightUnit = Arr::get($otherInfo, 'weight_unit', $storeWeightUnit);
                    $convertedProductWeight = Helper::convertWeight($productWeight, $productWeightUnit, $storeWeightUnit);

                    $packageSlug = Arr::get($otherInfo, 'package_slug', '');
                    $package = Helper::getPackageBySlug($packageSlug);
                    $packageWeight = 0;
                    if ($package) {
                        $packageWeightUnit = Arr::get($package, 'weight_unit', $storeWeightUnit);
                        $packageWeight = Helper::convertWeight(
                            floatval(Arr::get($package, 'weight', 0)),
                            $packageWeightUnit,
                            $storeWeightUnit
                        );
                    }

                    $totalWeight += ($convertedProductWeight + $packageWeight) * Arr::get($item, 'quantity', 1);
                }
            }

            $weightTiers = Arr::get($settings, 'weight_tiers', []);
            $methodBaseRate = 0;
            foreach ($weightTiers as $tier) {
                $min = floatval(Arr::get($tier, 'min', 0));
                $max = floatval(Arr::get($tier, 'max', 0));
                if ($totalWeight >= $min && ($max <= 0 || $totalWeight <= $max)) {
                    $methodBaseRate = Helper::toCent(floatval(Arr::get($tier, 'cost', 0)));
                    break;
                }
            }
        } else {
            // per_item
            $methodBaseRate = Helper::toCent($selectedMethod->amount) * $cartTotalQuantity;
        }

        // Accumulate class surcharges across all groups
        $allGroupsClassCharge = 0;
        $allGroupsMaxClassCharge = 0;

        foreach ($groups as $groupKey => &$group) {
            $classId = $group['class_id'];
            $groupItems = $group['items'];

            // Calculate class surcharges for this group
            $groupTotalClassCharge = 0;
            $groupMaxClassCharge = 0;

            foreach ($groupItems as &$gItem) {
                $quantity = Arr::get($gItem, 'quantity', 1);

                // Calculate class surcharge per item
                $itemClassCharge = 0;
                if ($classId && $shippingClasses->has($classId)) {
                    $sc = $shippingClasses->get($classId);
                    $factor = $sc->per_item ? $quantity : 1;
                    if ($sc->type === 'percentage') {
                        $itemClassCharge = ($sc->cost / 100) * Arr::get($gItem, 'unit_price', 0) * $factor;
                    } else {
                        $itemClassCharge = Helper::toCent($sc->cost) * $factor;
                    }
                }

                $gItem['shipping_charge'] = $itemClassCharge;
                $groupTotalClassCharge += $itemClassCharge;
                $groupMaxClassCharge = max($groupMaxClassCharge, $itemClassCharge);
            }
            unset($gItem);

            $allGroupsClassCharge += $groupTotalClassCharge;
            $allGroupsMaxClassCharge = max($allGroupsMaxClassCharge, $groupMaxClassCharge);

            $group['items'] = $groupItems;
            $group['class_charge'] = $groupTotalClassCharge;
        }
        unset($group);

        // Compute total: base rate (once) + class surcharges
        if ($classAggregation === 'highest_class') {
            $totalShippingAmount = $methodBaseRate + $allGroupsMaxClassCharge;
        } else {
            $totalShippingAmount = $methodBaseRate + $allGroupsClassCharge;
        }

        // Distribute the total shipping amount across all physical items proportionally
        $totalLineTotal = 0;
        foreach ($physicalItems as $item) {
            $totalLineTotal += Arr::get($item, 'line_total', 0);
        }

        $methodOnlyAmount = $methodBaseRate;
        $distributed = 0;
        $itemCount = count($physicalItems);

        // The last physical item overall (last item of the last group, in traversal order)
        // absorbs the exact remainder — per-item rounding must never change the total
        // the customer is charged for shipping.
        $lastGroupKey = array_key_last($groups);
        $lastItemIdx = ($lastGroupKey !== null && !empty($groups[$lastGroupKey]['items']))
            ? array_key_last($groups[$lastGroupKey]['items'])
            : null;

        foreach ($groups as $groupKey => &$group) {
            $groupItems = $group['items'];
            foreach ($groupItems as $idx => &$gItem) {
                if ($groupKey === $lastGroupKey && $idx === $lastItemIdx) {
                    $share = (int) round($methodOnlyAmount - $distributed);
                } elseif ($totalLineTotal > 0) {
                    $share = (int) round((Arr::get($gItem, 'line_total', 0) / $totalLineTotal) * $methodOnlyAmount);
                } else {
                    $share = $itemCount > 0 ? (int) round($methodOnlyAmount / $itemCount) : 0;
                }
                // itemwise_shipping_charge carries only the proportional base-rate share.
                // The class surcharge stays exclusively in shipping_charge (set above) so it
                // isn't taxed twice by TaxCalculator::getShippingTax(), which sums both fields.
                $gItem['itemwise_shipping_charge'] = $share;
                $distributed += $share;
            }
            unset($gItem);
            $group['items'] = $groupItems;
            $group['amount'] = $group['class_charge'];
        }
        unset($group);

        // Merge group items back into cartItems
        foreach ($groups as $group) {
            foreach ($group['keys'] as $i => $key) {
                if (isset($group['items'][$i])) {
                    $cartItems[$key]['shipping_charge'] = Arr::get($group['items'][$i], 'shipping_charge', 0);
                    $cartItems[$key]['itemwise_shipping_charge'] = Arr::get($group['items'][$i], 'itemwise_shipping_charge', 0);
                }
            }
        }

        if ($returnType === 'items') {
            return [
                'items'           => $cartItems,
                'shipping_amount' => $totalShippingAmount
            ];
        }

        return $totalShippingAmount;
    }

    public static function resetShippingCharge()
    {
        $cart = CartHelper::getCart();
        $items = $cart->cart_data;
        foreach ($items as $key => $item) {
            $items[$key]['shipping_charge'] = 0;
            $items[$key]['itemwise_shipping_charge'] = 0;
        }
        $cart->cart_data = $items;

        $cart->checkout_data = array_merge($cart->checkout_data, [
            'shipping_data' => [
                'shipping_method_id' => null,
                'shipping_charge'    => 0
            ]
        ]);

        $cart->save();

        do_action('fluent_cart/checkout/shipping_data_changed', [
            'cart' => $cart
        ]);
    }

    public static function generateCartFromVariation(ProductVariation $variation, $quantity = 1): Cart
    {
        $cart = new Cart();
        $cart->cart_data = [
            static::generateCartItemFromVariation($variation, $quantity)
        ];

        $cart = static::addCommonCartData($cart);
        return $cart;
    }

    public static function addCommonCartData(Cart $cart)
    {
        if (is_user_logged_in()) {
            $wpUser = wp_get_current_user();
            $cart->user_id = get_current_user_id();
            $customer = Customer::query()->where('email', wp_get_current_user()->user_email)->first();
            if ($customer) {
                $cart->customer_id = $customer->id;
            }
            $cart->email = $wpUser->user_email;
            $cart->first_name = $wpUser->first_name;
            $cart->last_name = $wpUser->last_name;
            $cart->ip_address = AddressHelper::getIpAddress();
            $cart->user_agent = AddressHelper::getUserAgent();
        }

        return $cart;
    }

    public static function generateCartFromCustomVariation(array $variation, $quantity = 1): Cart
    {
        $cart = new Cart();
        $cart->cart_data = [
            static::generateCartItemCustomItem($variation, $quantity)
        ];
        return $cart;
    }

    /**
     * Normalize custom item fields to standard cart variation format.
     *
     * NOTE:
     * - Custom items may originate from external sources (filters, adjustments, migrations)
     * - Some sources provide `id`, others only provide `item_id`
     * - For cart consistency, `id` is required and will fall back to `item_id` when missing
     * - This method intentionally mutates the provided object to normalize field names.
     *   The variation object is treated as a transient data structure and is not reused
     *   elsewhere after normalization.
     *
     * @param object $variation
     * @return object
     */
    public static function normalizeCustomFields(object $variation): object
    {
        // Map custom fields to native fields only if they exist
        $variation->id              = $variation->id ?? $variation->item_id;
        $variation->item_price      = $variation->item_price
            ?? $variation->unit_price
            ?? $variation->price
            ?? 0;
        $variation->variation_title = $variation->title ?? ($variation->variation_title ?? '');


        // Fallbacks
        $variation->post_id     = $variation->post_id ?? 0;
        $variation->object_id   = $variation->object_id ?? $variation->id;
        $variation->unit_price  = $variation->unit_price
            ?? $variation->item_price
            ?? $variation->price
            ?? 0;

        // Payment & fulfillment
        $variation->payment_type     = sanitize_text_field($variation->payment_type ?? 'onetime');
        $variation->fulfillment_type = sanitize_text_field($variation->fulfillment_type ?? 'digital');

        // Other info
        if (!empty($variation->other_info) && is_array($variation->other_info)) {
            $variation->other_info = $variation->other_info;
        } else {
            $variation->other_info = [];
        }

        // Add custom flags
        $variation->other_info['is_custom'] = $variation->is_custom ?? false;
        $variation->other_info['view_url']  = $variation->view_url  ?? '';

        return $variation;
    }


    /**
     * @param ProductVariation $variation
     * @param int|string $updatedQuantity
     * @return bool
     */
    public static function shouldAddItemToCart(ProductVariation $variation, $updatedQuantity): bool
    {
        if ($variation->manage_stock == 0) {
            return true;
        }
        return $updatedQuantity <= $variation->available;
    }

    public static function doingInstantCheckout()
    {
        $variationId = App::request()->get(Helper::INSTANT_CHECKOUT_URL_PARAM);
        if (empty($variationId)) {
            return false;
        }
        return $variationId;
    }

    /**
     * @param \FluentCart\App\Models\Cart $cart
     * @param array|\WP_Error             $methods
     * @param string|int|null             $currentSelectedId
     * @return string|int|null
     */
    public static function resolveAutoSelectShippingMethod($cart, $methods, $currentSelectedId)
    {
        if ($currentSelectedId || empty($methods) || is_wp_error($methods) || count($methods) !== 1) {
            return $currentSelectedId;
        }

        $method = $methods[0];

        $shouldAutoSelect = apply_filters('fluent_cart/shipping/auto_select_single_method', true, [
            'cart'   => $cart,
            'method' => $method,
        ]);

        if (!$shouldAutoSelect) {
            return $currentSelectedId;
        }

        $charge = static::calculateShippingMethodCharge($method, $cart->cart_data);

        $cart->checkout_data = array_merge(
            (array) $cart->checkout_data,
            [
                'shipping_data' => [
                    'shipping_method_id' => $method->id,
                    'shipping_charge'    => is_array($charge) ? Arr::get($charge, 'shipping_amount', 0) : $charge,
                ],
            ]
        );
        $cart->save();

        return $method->id;
    }
}
