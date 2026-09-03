<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\Coupon;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Str;

class CouponFilter extends BaseFilter
{

    public function buildQuery(): Builder
    {
        $query = parent::buildQuery();

        // The listing must show how many times each coupon was actually
        // applied — the stored use_count column can lag (it is decremented on
        // cancel and starts at 0 for imported rows). Same derived aggregate
        // CouponResource::get() already exposes.
        $query->withCount([
            'appliedCoupons as total_items' => function ($query) {
                $query->selectRaw('count(*)');
            }
        ]);

        return $query;
    }

    public function applySimpleFilter(?string $search = null): void
    {

        $this->query->when($search ?? $this->search, function ($query, $search) {
            return $query
                ->where(function ($query) use ($search) {
                    if (Str::of($search)->contains('%')) {
                        $query
                            ->where('type', 'percentage')
                            ->search([
                                'amount' => [
                                    'column'   => 'amount',
                                    'operator' => 'like_all',
                                    'value'    => Str::of($search)->remove('%')->toString()
                                ],
                            ]);
                    } else {

                        $searchArray = [
                            'title' => [
                                'column'   => 'title',
                                'operator' => 'like_all',
                                'value'    => $search
                            ],
                            'code'  => [
                                'column'   => 'code',
                                'operator' => 'or_like_all',
                                'value'    => $search
                            ],
                            'id'    => [
                                'column'   => 'id',
                                'operator' => 'or_like_all',
                                'value'    => $search
                            ]
                        ];
                        if (is_numeric($search)) {
                            $searchArray['amount'] = [
                                'column'   => 'amount',
                                'operator' => 'or_where',
                                'value'    => $search * 100
                            ];
                        }


                        $query->search($searchArray);
                    }
                });
        });
    }


    public function tabsMap(): array
    {
        return [
            'active'  => 'status',
            //'disabled' => 'status',
            'expired' => 'status',
        ];
    }

    public function getModel(): string
    {
        return Coupon::class;
    }

    public static function getFilterName(): string
    {
        return 'coupons';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'id'          => ['label' => __('ID', 'fluent-cart'), 'column' => 'id'],
            'title'       => ['label' => __('Title', 'fluent-cart'), 'column' => 'title'],
            'code'        => ['label' => __('Code', 'fluent-cart'), 'column' => 'code'],
            'amount'      => ['label' => __('Amount', 'fluent-cart'), 'column' => 'amount'],
            'stackable'   => ['label' => __('Stackable', 'fluent-cart'), 'column' => 'stackable'],
            'status'      => ['label' => __('Status', 'fluent-cart'), 'column' => 'status'],
            // The table has always offered this as "Expiry Date"; the column
            // behind it is end_date.
            'expiry_date' => ['label' => __('Expiry Date', 'fluent-cart'), 'column' => 'end_date'],
        ];
    }

    /**
     * No screen key: CouponTable.js sends no `with` at all. AllCoupons.vue and
     * CouponsTableMobile.vue render the real `use_count` column off the coupons
     * table, so no admin screen needs a relation here.
     *
     * PUBLIC key — `appliedCouponsCount` was an accepted request value before
     * this allow-list existed, so it stays one. "No consumer in our repos" is
     * not evidence of no consumer, and a name that used to answer and now
     * silently does not is the regression this tier exists to prevent.
     *
     * No permission bar: what comes back is a NUMBER, not the orders. A caller
     * learns how many times a coupon has been redeemed, which the coupon row's
     * own `use_count` column already tells them on the same response.
     *
     * `appliedCoupons` itself stays denied on its own merit: the ROWS say which
     * orders used which coupon, and that is order data behind `orders/view`.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [
            'appliedCouponsCount' => [$this, 'publicAppliedCouponsCount'],
        ];
    }

    /**
     * `with[]=appliedCouponsCount` — a public entry point. A count is not a
     * special case: it is a callback that calls withCount(), which surfaces as
     * the `applied_coupons_count` attribute.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicAppliedCouponsCount($query)
    {
        return $query->withCount('appliedCoupons');
    }

    /**
     * Sent by Orders/Coupon.vue when suggesting coupons to apply to an order.
     *
     * @return array<string, callable>
     */
    protected function allowedScopes(): array
    {
        return [
            'active' => [$this, 'activeScope'],
        ];
    }

    /**
     * Declared `($query)` on purpose: the array form of a `scopes` entry would
     * otherwise hand this callback raw client arguments, and Coupon's
     * `scopeActive()` takes none.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function activeScope($query)
    {
        return $query->scopes(['active']);
    }


    public function applyActiveViewFilter(?string $activeView = null): void
    {
        $activeView = $activeView ?? $this->activeView;
        $tabsMap = $this->tabsMap();

        if ($activeView === 'expired') {
            $this->query->where(function ($query) {
                $query->where('end_date', '<', DateTime::gmtNow())
                    ->where('end_date', '!=', '0000-00-00 00:00:00')
                    ->whereNotNull('end_date');
            })
                ->orWhere('status', '!=', 'active');
            return;
        }

        $this->query->when($activeView, function ($query, $activeView) use ($tabsMap) {
            $query->where($tabsMap[$activeView], $activeView);
        });
    }
}
