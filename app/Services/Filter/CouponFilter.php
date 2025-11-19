<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\Coupon;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Str;

class CouponFilter extends BaseFilter
{

    public function applySimpleFilter()
    {

        $this->query->when($this->search, function ($query, $search) {
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


    public function applyActiveViewFilter()
    {
        $tabsMap = $this->tabsMap();

        if ($this->activeView === 'expired') {
            $this->query->where(function ($query) {
                $query->where('end_date', '<', DateTime::gmtNow())
                    ->where('end_date', '!=', '0000-00-00 00:00:00')
                    ->whereNotNull('end_date');
            })
                ->orWhere('status', '!=', 'active');
            return;
        }

        $this->query->when($this->activeView, function ($query, $activeView) use ($tabsMap) {
            $query->where($tabsMap[$activeView], $activeView);
        });
    }
}
