<?php

namespace FluentCart\App\Http\Controllers;


use FluentCart\Api\Resource\CouponResource;
use FluentCart\Api\Resource\FluentMetaResource;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Helpers\CouponHelper;
use FluentCart\App\Http\Requests\CouponRequest;
use FluentCart\App\Http\Requests\FrontendRequests\CouponRequest as OrderCouponRequest;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Meta;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Filter\CouponFilter;

class CouponsController extends Controller
{

    public function index(Request $request)
    {
        $coupons = CouponFilter::fromRequest($request)->paginate();

        foreach ($coupons as $coupon) {
            CouponHelper::updateCouponStatus($coupon);
        }

        return $this->sendSuccess([
            'coupons' => $coupons
        ]);
    }

    public function viewDetails(Request $request, $id)
    {

        return ['coupon' => CouponResource::viewDetails($id)];
    }

    public function create(CouponRequest $request)
    {

        $data = $request->getSafe($request->sanitize());

        if (!empty($data['start_date'])) {
            $data['start_date'] = DateTime::anyTimeToGmt($data['start_date']);
        }

        if (!empty($data['end_date'])) {
            $data['start_date'] = DateTime::anyTimeToGmt($data['start_date']);
        }

        $isCreated = CouponResource::create($data);

        if (is_wp_error($isCreated)) {
            return $this->response->sendError($isCreated->get_error_message());
        }

        // Log activity for coupon creation
        $createdId = Arr::get($isCreated, 'data.id');
        if ($createdId) {
            \fluent_cart_add_log(
                __('Coupon Created', 'fluent-cart'),
                sprintf(
                    /* translators: 1: coupon title, 2: user display name */
                    __('Coupon "%1$s" created by %2$s', 'fluent-cart'), Arr::get($isCreated, 'data.title', ''), wp_get_current_user()->display_name ?? 'FCT-BOT'),
                'success',
                [
                    'module_name' => 'coupon',
                    'module_id'   => intval($createdId)
                ]
            );
        }


        do_action('fluent_cart/coupon_created', [
            'data'   => $data,
            'coupon' => $isCreated['data']
        ]);
        return $this->response->sendSuccess($isCreated);
    }

    public function update(CouponRequest $request, $id)
    {

        $data = $request->getSafe($request->sanitize());

        if (!empty($data['start_date'])) {
            $data['start_date'] = DateTime::anyTimeToGmt($data['start_date']);
        }

        if (!empty($data['end_date'])) {
            $data['end_date'] = DateTime::anyTimeToGmt($data['end_date']);
        }


        $isUpdated = CouponResource::update($data, $id);


        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        do_action('fluent_cart/coupon_updated', [
            'data'   => $data,
            'coupon' => $isUpdated['data']
        ]);

        // Log activity for coupon update
        $updatedId = Arr::get($isUpdated, 'data.id', $id);
        if ($updatedId) {
            \fluent_cart_add_log(
                __('Coupon Updated', 'fluent-cart'),
                sprintf(
                /* translators: 1: coupon title, 2: user display name */
                    __('Coupon "%1$s" updated by %2$s', 'fluent-cart'), Arr::get($isUpdated, 'data.title', ''), wp_get_current_user()->display_name ?? 'FCT-BOT'),
                'info',
                [
                    'module_name' => 'coupon',
                    'module_id'   => intval($updatedId)
                ]
            );
        }


        return $this->response->sendSuccess($isUpdated);
    }

    public function delete(Request $request)
    {

        $id = Arr::get($request->all(), 'id', null);
        $isDeleted = CouponResource::delete($id);

        if (is_wp_error($isDeleted)) {
            return $isDeleted;

        }
        return $this->response->sendSuccess($isDeleted);
    }

    public function applyCoupon(OrderCouponRequest $request)
    {
        $data = $request->getSafe($request->sanitize());
        $isApplied = CouponResource::applyCoupon($data);

        if (is_wp_error($isApplied)) {
            return $isApplied;
        }
        return $this->response->sendSuccess($isApplied);
    }


    public function cancelCoupon(OrderCouponRequest $request)
    {
        $data = $request->getSafe($request->sanitize());
        $isCancelled = CouponResource::cancelCoupon($data);

        if (is_wp_error($isCancelled)) {
            return $isCancelled;

        }
        return $this->response->sendSuccess($isCancelled);
    }

    public function reapplyCoupon(Request $request)
    {
        $data = $request->getSafe([
            'order_uuid'         => 'sanitize_text_field',
            'applied_coupons.*' => 'intval',
            'order_items.*'     => 'sanitize_text_field',
        ]);

        $isApplied = CouponResource::reapplyCoupon($data);

        if (is_wp_error($isApplied)) {
            return $isApplied;
        }
        return $this->response->sendSuccess($isApplied);
    }


    public function listCoupons(Request $request)
    {
        return ['coupons' => CouponResource::listCoupon()];
    }

    public function isProductEligible(Request $request)
    {
        $productId = Arr::get($request->all(), 'productId', null);
        $appliedCoupons = Arr::get($request->all(), 'appliedCoupons', []);
        $origin = Arr::get($request->all(), 'origin', null);
        $checkEligibility = CouponResource::checkProductEligibility($productId, $appliedCoupons, $origin);
        return $checkEligibility;
    }

    public function storeCouponSettings(Request $request)
    {
        $id = "";
        $params = [];
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $data['meta_key'] = 'fluent_cart_coupon_settings';
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $data['meta_value'] = $showOnCheckout = Arr::get($request->all(), 'show_on_checkout', false) ? 1 : 0;

        $isSaved = FluentMetaResource::update($data, $id, $params);
        if (is_wp_error($isSaved)) {
            return $isSaved;
        }
        return $isSaved;
    }

    public function getSettings()
    {
        $couponSettings = Meta::where('meta_key', 'fluent_cart_coupon_settings')->first();
        return $this->response->sendSuccess([
            'show_on_checkout' => $couponSettings ? $couponSettings['value'] : false
        ]);
    }
}
