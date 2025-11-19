<?php

namespace FluentCart\App\Services;

use FluentCart\App\Models\Order;
use FluentCart\Framework\Support\Arr;

class OrdersQuery
{
    private $args = [];

    private $model = null;

    public function __construct($args = [])
    {
        $this->args = wp_parse_args($args, [
            'with'               => ['customer', 'order_items'],
            'filter_type'        => 'simple',
            'filters_groups'     => [],
            'filters_groups_raw' => [],
            'search'             => '',
            'sort_by'            => 'id',
            'sort_type'          => 'DESC',
            'payment_statuses'   => [], // payment_status
            'order_statuses'     => [], // status
            'shipping_statuses'  => [], // shipping_status
            'limit'              => false,
            'offset'             => false,
            'payment_status'     => '',
            'active_view'        => 'all'
        ]);

        $this->setupQuery();
    }

    private function setupQuery()
    {
        if ($this->args['filters_groups_raw']) {
            $this->formatAdvancedFilters();
        }

        $ordersQuery = Order::with($this->args['with']);

        if ($sortBy = $this->args['sort_by']) {
            if (in_array($sortBy, (new Order())->getFillable()) || $sortBy == 'id') {
                $ordersQuery->orderBy($sortBy, $this->args['sort_type']);
            }
        }

        if ($this->args['filter_type'] == 'advanced') {
            $filtersGroups = $this->args['filters_groups'];
            $ordersQuery->where(function ($queryGroup) use ($filtersGroups) {
                foreach ($filtersGroups as $groupIndex => $group) {
                    $method = 'orWhere';
                    if ($groupIndex == 0) {
                        $method = 'where';
                    }

                    $queryGroup->{$method}(function ($q) use ($group) {
                        foreach ($group as $providerName => $items) {
                            do_action_ref_array('fluentcart/orders_filter_' . $providerName, [&$q, $items]);
                        }
                    });
                }
            });
        } else {
            if ($paymentStatuses = $this->args['payment_statuses']) {
                $ordersQuery->whereIn('payment_status', $paymentStatuses);
            }

            if ($orderStatuses = $this->args['order_statuses']) {
                $ordersQuery->whereIn('status', $orderStatuses);
            }

            if ($shippingStatuses = $this->args['shipping_statuses']) {
                $ordersQuery->whereIn('shipping_status', $shippingStatuses);
            }

            if ($search = $this->args['search']) {
                $ordersQuery->searchBy($search);
            }
        }

        $acceptedViewsMaps = [
            'on-hold' => 'status',
            'paid' => 'payment_status',
            'unpaid' => 'payment_status',
            'completed' => 'status',
            'processing' => 'status',
            'renewal' => 'type',
            'subscription' => 'type',
        ];

        $activeView = $this->args['active_view'];

        if (isset($acceptedViewsMaps[$activeView])) {
            $ordersQuery->where($acceptedViewsMaps[$activeView], $activeView);
        }

        $this->model = $ordersQuery;
    }

    public function get()
    {
        $orderModel = $this->model;

        if ($limit = $this->args['limit']) {
            $orderModel = $orderModel->limit($limit);
        }

        if ($offset = $this->args['offset']) {
            $orderModel = $orderModel->offset($offset);
        }

        return $this->returnOrders($orderModel->get());
    }

    public function paginate($perPage = 10)
    {
        return $this->returnOrders($this->model->paginate($perPage));
    }

    public function getModel()
    {
        return $this->model;
    }

    private function returnOrders($orders)
    {
        return $orders;
    }

    private function formatAdvancedFilters()
    {
        $filters = $this->args['filters_groups_raw'];
        $groups = [];

        foreach ($filters as $filterGroup) {
            $group = [];
            foreach ($filterGroup as $filterItem) {
                if (count($filterItem['source']) != 2 || empty($filterItem['source'][0]) || empty($filterItem['source'][1]) || empty($filterItem['operator'])) {
                    continue;
                }
                $provider = $filterItem['source'][0];

                if (!isset($group[$provider])) {
                    $group[$provider] = [];
                }

                $property = $filterItem['source'][1];

                $group[$provider][] = [
                    'property'    => $property,
                    'operator'    => $filterItem['operator'],
                    'value'       => $filterItem['value']
                ];
            }

            if ($group) {
                $groups[] = $group;
            }
        }

        $this->args['filters_groups'] = $groups;
    }

}
