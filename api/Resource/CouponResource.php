<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\Helpers\CouponHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Coupon\CouponServiceAdmin;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use WP_Error;

/**
 * Class CouponResource
 *
 * @package FluentCart\Api\Resource
 */
class CouponResource extends BaseResourceApi
{
    /**
     * Upper bound on the applied-coupon list a single eligibility check will accept.
     */
    const MAX_ELIGIBILITY_COUPONS = 50;

    /**
     * Get the query builder instance for the Coupons model.
     *
     * @return Builder
     */

    public static function getQuery(): Builder
    {
        return Coupon::query();
    }

    public static function get(array $params = [])
    {
        $coupons = static::getQuery()
            ->withCount([
                'appliedCoupons as total_items' => function ($query) {
                    $query->selectRaw('count(*)');
                }
            ])
            ->orderBy(
                sanitize_sql_orderby(Arr::get($params, 'order_by', 'id')),
                sanitize_sql_orderby(Arr::get($params, 'order_type', 'DESC'))
            )
            ->paginate(Arr::get($params, 'per_page', 15), ['*'], 'page', Arr::get($params, 'page'));

        return [
            'data' => $coupons,
        ];
    }

    public static function find($id, $params = [])
    {
        $type = Arr::get($params, 'type', '');

        if (!empty($type) && $type === 'byCode') {
            $code = Arr::get($params, 'code', null);
            $coupon = static::getQuery()->whereRaw("BINARY `code` = ?", [$code])->first();
            return $coupon;
        }

        return static::getQuery()->find($id);
    }

    public static function viewDetails($id)
    {
        $coupon = static::getQuery()->with(['activities.user'])->find($id);

        if (!empty($coupon->end_date) && $coupon->end_date !== '0000-00-00 00:00:00') {
            $endDate = DateTime::anyTimeToGmt($coupon->end_date);
            $now = new DateTime();

            if ($endDate < $now && $coupon->getStatus() !== 'expired') {
                $coupon->setStatus('expired');
                $coupon->save();
            }
        }
        return $coupon;

    }


    public static function create($data, $params = [])
    {
        // New coupons default the spend basis to subtotal (the documented behavior) when a client
        // omits it. Only applies on create — there is no prior behavior to preserve for a new row.
        if (!is_array(Arr::get($data, 'conditions'))) {
            $data['conditions'] = [];
        }
        if (empty($data['conditions']['min_amount_basis'])) {
            $data['conditions']['min_amount_basis'] = 'subtotal';
        }

        $data = self::formatAmount($data);
        $isCreated = static::getQuery()->create($data);

        if ($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('Coupon created successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Coupon creation failed.', 'fluent-cart')]
        ]);
    }

    public static function listCoupon()
    {
        return static::getQuery()
            ->where('status', 'active')
            ->pluck('code')
            ->toArray();
    }

    public static function update($data, $id, $params = [])
    {
        if (Arr::get($data, 'max_uses', null) == '') {
            $data['max_uses'] = null;
        }
        if (Arr::get($data, 'max_per_customer', null) == '') {
            $data['max_per_customer'] = null;
        }
        if (Arr::get($data, 'max_discount_amount', null) == '') {
            $data['max_discount_amount'] = null;
        }
        if (Arr::get($data, 'min_purchase_amount', null) == '') {
            $data['min_purchase_amount'] = null;
        }

        if (!$id) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Please edit a valid coupon!', 'fluent-cart')]
            ], 403);
        }

        $hasCoupon = self::find($id);

        if (!$hasCoupon) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Coupon not found, please reload the page and try again!', 'fluent-cart')]
            ], 404);
        }

        // If the client sends conditions without a valid spend basis (e.g. an older REST client that
        // predates the field), keep whatever is already stored so an unrelated edit never silently
        // flips the coupon's spend basis. Legacy coupons with no stored basis stay absent and fall
        // back to 'total' at validation.
        if (is_array(Arr::get($data, 'conditions')) && empty($data['conditions']['min_amount_basis'])) {
            $existingBasis = Arr::get($hasCoupon->conditions, 'min_amount_basis');
            if (!empty($existingBasis)) {
                $data['conditions']['min_amount_basis'] = $existingBasis;
            }
        }

        $data = self::formatAmount($data);

        $isUpdated = $hasCoupon->update($data);

        if ($isUpdated) {
            return static::makeSuccessResponse($hasCoupon, __('Coupon updated successfully!', 'fluent-cart'));
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Coupon update failed.', 'fluent-cart')]
        ], 400);
    }

    public static function delete($id, $params = [])
    {
        if (!$id) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Please use a valid coupon ID!', 'fluent-cart')]
            ], 403);
        }

        $hasCoupon = self::find($id);

        if ($hasCoupon) {
            if ($hasCoupon->delete()) {
                return static::makeSuccessResponse('', __('Coupon successfully deleted.', 'fluent-cart'));
            }

            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Coupon deletion failed!', 'fluent-cart')]
            ], 400);
        }

        return static::makeErrorResponse([
            ['code' => 404, 'message' => __('Coupon not found in database, failed to remove.', 'fluent-cart')]
        ], 404);
    }

    private static function formatAmount($data)
    {
        if (empty(Arr::get($data, 'conditions.max_discount_amount', ''))) {
            $data['max_discount_amount'] = null;
        }
        if (Arr::get($data, 'type') !== 'percentage') {
            $data['amount'] = Helper::toCent(Arr::get($data, 'amount', 0));
        }
        if (!empty(Arr::get($data, 'conditions.max_discount_amount'))) {
            $data['conditions']['max_discount_amount'] = Helper::toCent($data['conditions']['max_discount_amount']);
        }
        $data['conditions']['min_purchase_amount'] = Helper::toCent(Arr::get($data['conditions'], 'min_purchase_amount', 0));
        return $data;
    }

    public static function applyCoupon($data, $returnCouponService = true)
    {
        $couponCode = Arr::get($data, 'coupon_code');
        $lineItems = Arr::except(Arr::get($data, 'order_items', []), ['*']);
        $orderId = Arr::get($data, 'order_uuid', null);
        $getAppliedCouponLists = Arr::get($data, 'applied_coupons', []);
        $customerEmail = (string) Arr::get($data, 'customer_email', '');
        $previouslyAppliedCouponCodes = new Collection();
        if (!empty($orderId)) {
            $order = OrderResource::find($orderId, ['with' => ['appliedCoupons', 'customer']]);
            $customerEmail = $order->customer->email ?? $customerEmail;
            $previouslyAppliedCouponCodes = $order->appliedCoupons;
            $previouslyAppliedCouponCodes = static::makeCouponFromAppliedCoupons($previouslyAppliedCouponCodes);
        }
        $appliedKeys = $previouslyAppliedCouponCodes->pluck('id')->toArray();
        $getAppliedCouponLists = array_diff($getAppliedCouponLists, $appliedKeys);

        if (!empty($getAppliedCouponLists)) {
            $getAppliedCouponLists = Coupon::query()->whereIn('id', $getAppliedCouponLists)->get()->keyBy('code')->toArray();
            $previouslyAppliedCouponCodes = $previouslyAppliedCouponCodes->merge($getAppliedCouponLists);
        }

        $couponService = new CouponServiceAdmin($lineItems, null, $previouslyAppliedCouponCodes->keys()->toArray(), $customerEmail);
        $couponApplied = $couponService->applyCoupon($couponCode);
        if (is_wp_error($couponApplied)) {
            return $couponApplied;
        }

        $calculated_items = $couponService->getCalculatedLineItems();

        $applied_coupons = $couponService->getDiscountData();

        $errors = $couponService->getCouponErrors();


        if($errors->has($couponCode)){
            // 422: without the status argument the WP_Error carries no
            // data.status and WP_REST_Server answers 500 for what is a
            // validation outcome (stacking, expiry, limits, spend gates).
            // The update/delete paths already pass their status the same way.
            return static::makeErrorResponse([
                ['code' => 400, 'message' => $errors->get($couponCode)->get_error_message()]
            ], 422);
        }

        if ($returnCouponService) {
            return [
                'applied_coupons'  => $applied_coupons,
                'calculated_items' => $calculated_items,
            ];
        }

        return new WP_Error(__('Something went wrong while updating the cart.', 'fluent-cart'));
    }

    public static function cancelCoupon($data, $returnCouponService = true)
    {
        $couponId = Arr::get($data, 'id', null);
        $couponCode = Arr::get($data, 'coupon_code', null);
        $lineItems = Arr::except(Arr::get($data, 'order_items', []), ['*']);
        $orderId = Arr::get($data, 'order_uuid', null);
        $getAppliedCouponLists = Arr::get($data, 'applied_coupons', []);
        $customerEmail = (string) Arr::get($data, 'customer_email', '');
        $previouslyAppliedCouponCodes = new Collection();

        if (!empty($orderId)) {
            $order = OrderResource::find($orderId, ['with' => ['order_items', 'appliedCoupons', 'customer']]);
            $customerEmail = $order->customer->email ?? $customerEmail;
            if (!empty($couponId)) {
                $order->appliedCoupons()->where('id', $couponId)->delete();
                Coupon::query()->where('code', $couponCode)->decrement('use_count', 1);
            }
        }


        if (!empty($getAppliedCouponLists)) {
            $getAppliedCouponLists = Coupon::query()->whereIn('id', $getAppliedCouponLists)->get()->keyBy('code')->toArray();
            $previouslyAppliedCouponCodes = Collection::make($getAppliedCouponLists);
        }


        $couponService = new CouponServiceAdmin($lineItems, null, $previouslyAppliedCouponCodes->keys()->toArray(), $customerEmail);

        $isCancelled = $couponService->cancelCoupon($couponCode);

        if (is_wp_error($isCancelled)) {
            return $isCancelled;
        }


        $calculated_items = $couponService->getCalculatedLineItems();

        $applied_coupons = $couponService->getDiscountData();

        if ($returnCouponService) {
            return [
                'applied_coupons'  => $applied_coupons,
                'calculated_items' => $calculated_items,
            ];
        }

        return new WP_Error(__('Something went wrong while updating the cart.', 'fluent-cart'));
    }

    public static function reapplyCoupon($data, $returnCouponService = true)
    {
        $lineItems = Arr::except(Arr::get($data, 'order_items', []), ['*']);
        $orderId = Arr::get($data, 'order_uuid', null);
        $appliedCouponIds = Arr::get($data, 'applied_coupons', []);
        $customerEmail = (string) Arr::get($data, 'customer_email', '');
        $previouslyAppliedCouponCodes = null;

        if (!empty($orderId)) {
            $order = OrderResource::find($orderId, ['with' => ['appliedCoupons', 'customer']]);
            $customerEmail = $order->customer->email ?? $customerEmail;
            if (empty($lineItems)) {
                $order->appliedCoupons()->delete();
            } else {
                $previouslyAppliedCouponCodes = $order->appliedCoupons;
            }
        }

        // When the request carries coupon IDs (current UI state, may include unsaved coupons),
        // load them directly so freshly applied coupons survive item adjustments before save.
        if (!empty($appliedCouponIds)) {
            $couponCodes = Coupon::query()->whereIn('id', $appliedCouponIds)->pluck('code')->toArray();
            $couponService = new CouponServiceAdmin($lineItems, null, $couponCodes, $customerEmail);
        } else {
            $previouslyAppliedCouponCodes = static::makeCouponFromAppliedCoupons($previouslyAppliedCouponCodes);
            $couponService = new CouponServiceAdmin($lineItems, $previouslyAppliedCouponCodes, [], $customerEmail);
        }

        $couponService->reapplyCoupons();

        $calculated_items = $couponService->getCalculatedLineItems();

        $applied_coupons = $couponService->getDiscountData();

        if ($returnCouponService) {
            return [
                'applied_coupons'  => $applied_coupons,
                'calculated_items' => $calculated_items,
            ];
        }

        return new WP_Error(__('Something went wrong while updating the cart.', 'fluent-cart'));
    }

    /**
     * Report whether a product may be bought while the given coupons are applied.
     *
     * A product is eligible only when every applied coupon accepts it, so the first
     * rejection wins and carries its message back to the caller.
     *
     * @param int $productId
     * @param array $appliedCouponCodes Coupon codes, per the published API contract.
     * @param string|null $origin Context the check came from; not currently branched on.
     * @return array|WP_Error
     */
    public static function checkProductEligibilityForCodes($productId, $appliedCouponCodes = [], $origin = null)
    {
        $appliedCouponCodes = (array)$appliedCouponCodes;

        // Rejected rather than truncated: a silently shortened list would answer a question
        // the caller did not ask. An order carries a handful of coupons, never this many.
        if (count($appliedCouponCodes) > static::MAX_ELIGIBILITY_COUPONS) {
            return new WP_Error(
                'too_many_applied_coupons',
                sprintf(
                    /* translators: %d: maximum number of applied coupons accepted per request */
                    __('Too many applied coupons supplied. Send at most %d.', 'fluent-cart'),
                    static::MAX_ELIGIBILITY_COUPONS
                ),
                ['status' => 400]
            );
        }

        $codes = [];

        foreach ($appliedCouponCodes as $appliedCouponCode) {
            if (!is_scalar($appliedCouponCode) || is_bool($appliedCouponCode) || $appliedCouponCode === '') {
                continue;
            }

            $codes[(string)$appliedCouponCode] = (string)$appliedCouponCode;
        }

        if (!$codes) {
            return [
                'isApplicable' => true,
            ];
        }

        $productModel = Product::with('wp_terms')->find($productId);

        if (!$productModel) {
            return [
                'isApplicable' => false,
                'message'      => __('Product not found.', 'fluent-cart'),
            ];
        }

        $coupons = static::getQuery()
            ->whereIn('code', array_values($codes))
            ->get()
            ->keyBy('code');

        foreach ($codes as $code) {
            // Direct access, not Arr::get — a coupon code may legitimately contain a dot.
            $coupon = isset($coupons[$code]) ? $coupons[$code] : null;

            if (!$coupon) {
                return [
                    'isApplicable' => false,
                    'message'      => __('This coupon is no longer available.', 'fluent-cart'),
                ];
            }

            $eligibility = CouponHelper::evaluateProductEligibility($productModel, $coupon);

            if (!Arr::get($eligibility, 'isApplicable')) {
                return [
                    'isApplicable' => false,
                    'message'      => Arr::get($eligibility, 'message', ''),
                ];
            }
        }

        return [
            'isApplicable' => true,
        ];
    }

    private static function makeCouponFromAppliedCoupons($previouslyAppliedCouponCodes)
    {
        if (empty($previouslyAppliedCouponCodes)) {
            return new Collection();
        }

        return $previouslyAppliedCouponCodes->mapWithKeys(function ($coupon) {
            return [
                $coupon['code'] => [
                    'id'         => Arr::get($coupon, 'coupon_id', ''),
                    'title'      => $coupon['title'],
                    'priority'   => $coupon['priority'] ?? 1,
                    'code'       => $coupon['code'],
                    'amount'     => $coupon['amount'],
                    'type'       => 'fixed',
                    'stackable'  => 'yes',
                    'conditions' => [
                        'min_purchase_amount' => 0,
                        'max_purchase_amount' => 0,
                        'included_products'   => [],
                        'excluded_products'   => [],
                        'included_categories' => [],
                        'excluded_categories' => [],
                        'allowed_user_ids'    => [],
                        'allowed_roles'       => [],
                        'max_uses'            => 0,
                        'max_per_customer'    => 0,
                    ]
                ]
            ];
        });
    }
}
