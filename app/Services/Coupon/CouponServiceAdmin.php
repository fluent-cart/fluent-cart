<?php

namespace FluentCart\App\Services\Coupon;

use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Coupon\Concerns\CanCalculateLineTotal;
use FluentCart\App\Services\Coupon\Concerns\CanValidateCoupon;
use FluentCart\Framework\Support\Collection;
use WP_Error;

/*
* Class CouponService was removed as all coupon logic goes to DiscountService.php
* we had to bring it back and make it as CouponServiceAdmin
*
* @devnotes: for admin order this is used to apply/cancel/reapply coupons.
* we may refactore this later
*/

class CouponServiceAdmin
{
    use CanValidateCoupon, CanCalculateLineTotal;

    protected ?Collection $previouslyAppliedCoupons;
    protected array $previouslyAppliedCouponCodes = [];
    protected ?Collection $applicableCoupons;
    protected ?Collection $appliedCoupons;
    protected array $applicableCouponCodes = [];
    protected array $cancelableCouponCodes = [];
    protected array $productIds = [];
    protected ?Collection $products;
    protected array $lineItems = [];
    protected array $calculatedLineItems = [];
    protected bool $usingCart = true;
    protected ?Collection $couponErrors = null;


    /**
     * @param array $lineItems
     * @param ?Collection $previouslyAppliedCoupons
     * @param bool $usingCart
     */
    public function __construct(array $lineItems = [], ?Collection $previouslyAppliedCoupons = null, $applicableCouponCodes = [])
    {
        $this->appliedCoupons = $previouslyAppliedCoupons;
        $this->couponErrors = new Collection();

        //Set Data from the parameters
        $this->lineItems = $lineItems;
        $this->previouslyAppliedCoupons = $previouslyAppliedCoupons === null ? new Collection() : $previouslyAppliedCoupons->keyBy('code');

        $this->previouslyAppliedCouponCodes = $this->previouslyAppliedCoupons->pluck('code')->toArray();
        $this->applicableCouponCodes = array_merge($this->applicableCouponCodes, $applicableCouponCodes);
        $this->init();
    }

    private function init()
    {
        $this->calculatedLineItems = $this->lineItems;
        $this->productIds = (new Collection($this->lineItems))
            ->pluck('post_id')
            ->toArray();

        $this->productIds = array_unique($this->productIds);
        $this->products = Product::query()
            ->select('ID')
            ->whereIn('ID', $this->productIds)
            ->with('categories')
            ->get()
            ->keyBy('ID');
    }


    public function initializeCoupons(array $couponCodes = [])
    {
        $originalOrder = array_unique($this->applicableCouponCodes);

        //Remove the cancelable code from the applicable coupon codes;
        $this->applicableCouponCodes = array_merge(
            $this->previouslyAppliedCouponCodes,
            $this->applicableCouponCodes,
            $couponCodes
        );

        $this->applicableCouponCodes = array_unique($this->applicableCouponCodes);

        $orderMap = array_flip($originalOrder); // ['d' => 0, 'b' => 1, 'a' => 2]

        usort($this->applicableCouponCodes, function ($a, $b) use ($orderMap) {
            $indexA = $orderMap[$a] ?? PHP_INT_MAX;
            $indexB = $orderMap[$b] ?? PHP_INT_MAX;
            return $indexA <=> $indexB;
        });

        //Remove from the collection list if it should be canceled.
        foreach ($this->cancelableCouponCodes as $couponCode) {
            $this->applicableCouponCodes = array_diff($this->applicableCouponCodes, [$couponCode]);
        }

        if (empty($this->applicableCouponCodes)) {
            $this->applicableCoupons = new Collection();
        } else {
            $this->applicableCoupons = $this->populateCouponsFromModel($this->applicableCouponCodes);
        }


        $sortedCoupons = new Collection();
        foreach ($this->applicableCouponCodes as $couponCode) {
            if ($this->applicableCoupons->has($couponCode)) {
                $sortedCoupons->put($couponCode, $this->applicableCoupons->get($couponCode));
            }
        }

        $this->applicableCoupons = $sortedCoupons;

        $this->appliedCoupons = $this->appliedCoupons ?? new Collection();
        $this->appliedCoupons = $this->appliedCoupons->keyBy('code');
        $appliedCoupons = $this->appliedCoupons;

        $this->applicableCoupons = $this->applicableCoupons->map(function ($coupon) use ($appliedCoupons) {
            if ($appliedCoupons->has($coupon->code)) {
                $data = $appliedCoupons->get($coupon->code);
                $data['id'] = $coupon->id;

                return $this->populateCouponFromArray($data);
            }

            return $coupon;
        });

        $this->applicableCouponCodes = $this->applicableCoupons->keys()->toArray();

    }

    public function getCouponErrors(): ?Collection
    {
        return $this->couponErrors;
    }

    protected function populateCouponFromArray($data): Coupon
    {
        $coupon = new Coupon();
        $coupon->fill($data);
        $coupon->id = $data['id'];
        return $coupon;
    }

    protected function populateCouponsFromModel($couponCodes = [], $model = Coupon::class)
    {
        return $model::query()
            ->whereIn('code', $couponCodes) // Lowercase the array for comparison
            ->orderBy('priority')//Order by is important here
            ->get()
            ->keyBy('code');
    }

    public function applyCoupon(string $couponCode): ?WP_Error
    {
        return $this->apply([$couponCode]);
    }

    protected function apply(array $couponCodes = []): ?WP_Error
    {
        $this->initializeCoupons($couponCodes);
        $this->calculateLineItems();
        return null;
    }

    public function getCalculatedLineItems(): array
    {
        return $this->calculatedLineItems;
    }

    public function cancelCoupon(string $couponCode)
    {
        $this->cancelableCouponCodes = array_merge(
            $this->cancelableCouponCodes,
            [$couponCode]
        );

        $this->initializeCoupons();
        $this->calculateLineItems();
    }

    public function reapplyCoupons()
    {
        $this->initializeCoupons();
        $this->calculateLineItems();
    }

    protected function ensureCouponExistInDiscountData(Coupon $coupon)
    {
        if (empty($this->discountData[$coupon->code])) {
            $this->discountData[$coupon->code] = [
                'id'                 => $coupon->id,
                'title'              => $coupon->title,
                'amount'             => 0,
                'formatted_discount' => '',
                'unit_amount'        => 0,
                'actual_quantity'    => 0,
                'discount'           => 0,
                'type'               => $coupon->type,
                'actual_amount'      => $coupon->amount,
            ];
        }
    }

}
