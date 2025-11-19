<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Query\QueryParser;
use FluentCart\App\Models\Query\Sort;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class SubscriptionResource extends BaseResourceApi
{
    public static function getQuery(): Builder
    {
        return Order::query()
            ->where(function (Builder $query) {
                $query->where('parent_id', 0)
                    ->orWhereNull('parent_id');
            })
            ->whereHas('order_items', function (Builder $query) {
                $query->where('payment_type', 'subscription');
            });
    }

    public static function get(array $params = [])
    {

        $query = Subscription::query();

        $dynamicConditions = Arr::get($params, 'dynamic_filters') ?? [];
        QueryParser::make()->parse($query, $dynamicConditions);

        $activeView = Arr::get($params, 'active_view');
        $acceptedViewsMaps = [
            Status::SUBSCRIPTION_ACTIVE => 'status',
            Status::SUBSCRIPTION_PENDING => 'status',
            Status::SUBSCRIPTION_INTENDED => 'status',
            Status::SUBSCRIPTION_PAUSED => 'status',
            Status::SUBSCRIPTION_TRIALING => 'status',
            Status::SUBSCRIPTION_CANCELED => 'status',
            Status::SUBSCRIPTION_FAILING => 'status',
            Status::SUBSCRIPTION_EXPIRING => 'status',
            Status::SUBSCRIPTION_EXPIRED => 'status',
        ];

        if(isset($acceptedViewsMaps[$activeView])) {
            $query->where($acceptedViewsMaps[$activeView], $activeView);
        }

        $sortBy = Arr::get($params, 'sort_by', 'id');
        $sortType = Arr::get($params, 'sort_type', 'DESC');
        
        $allowedSortFields = ['id', 'next_billing_date', 'created_at', 'status'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortType);
        }

        $search = trim(Arr::get($params, 'search', ''));
        $searchTerms = explode(' ', $search);

        $sortCriteria = Arr::get($params, 'sort_criteria', []);
        Sort::make()->apply($query, $sortCriteria);

        $with = ['customer'];

        return $query->with($with)
            ->where(function ($q) use ($searchTerms) {
                $q->where('id', 'LIKE', "%{$searchTerms[0]}%")
                    ->orWhere('status', 'LIKE', "%{$searchTerms[0]}%")
                    ->orWhere('item_name', 'LIKE', "%{$searchTerms[0]}%")
                    ->orWhere('current_payment_method', 'LIKE', "%{$searchTerms[0]}%")
                    ->orWhere('parent_order_id', 'LIKE', "%{$searchTerms[0]}%")
                    ->orWhereHas('customer', function ($query) use ($searchTerms) {
                        $query->where('first_name', 'LIKE', "%{$searchTerms[0]}%")
                            ->orWhere('last_name', 'LIKE', "%{$searchTerms[0]}%");
                    });
            })
            ->paginate(Arr::get($params, 'per_page'), ['*'], 'page', Arr::get($params, 'page'));
    }


    public static function find($id, $params = [])
    {

    }

    public static function create($data, $params = [])
    {

    }


    public static function update($data, $id, $params = [])
    {

    }

    public static function delete($id, $params = [])
    {

    }

    public static function view($id) 
    {
        return Subscription::where('id', $id)
                            ->with([
                                'customer.shipping_address' => function ($query) {
                                    $query->where('is_primary', 1);
                                },
                                'customer.billing_address' => function ($query) {
                                    $query->where('is_primary', 1);
                                },
                                'activities',
                                'order.transactions'
                            ])
                            ->first();
    }
}
