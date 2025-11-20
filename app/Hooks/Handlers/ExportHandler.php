<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use FluentCart\Api\Resource\ProductResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\CustomerHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Support\Arr;

class ExportHandler
{
    public function register()
    {
        add_action('wp_ajax_fluent_cart_admin_ajax', array($this, 'mapAjaxRoute'));
    }

    public function mapAjaxRoute()
    {
        $route = App::getInstance()->request->get('route');
        $route = sanitize_text_field($route);

        $validRoutes = [
            'export_orders' => 'exportOrders',
            'export_repeat_customers' => 'exportRepeatCustomers',
            'export_products' => 'exportProducts',
        ];

        if (isset($validRoutes[$route])) {
            return $this->{$validRoutes[$route]}();
        }

        die('No Route Found');
    }

    public function exportOrders()
    {
        if (!current_user_can('manage_options')) {
            die('No Access');
        }

        Helper::loadSpoutLib();

        $writer = WriterEntityFactory::createCSVWriter();

        $writer->openToBrowser('orders-export-' . gmdate('Y-m-d_H:i') . '.csv');

        $values = [
            'Order ID',
            'Order Status',
            // 'Shipping Status',
            'Currency',
            'Total Amount',
            'Items Count',
            'Order Type',
            'Payment Method',
            'Order Date',
            'Customer ID',
            'Customer Name',
            'Customer Email',
        ];

        $rowFromValues = WriterEntityFactory::createRowFromArray($values);
        $writer->addRow($rowFromValues);

        $request = App::getInstance()->request->all();

        $ordersQuery = Order::with(['order_items', 'customer'])
            ->whereHas('customer', function ($query) use ($request) {
                $query->when(Arr::get($request, 'search'), function ($search) use ($request) {
                    return $search->search(Arr::get($request, 'search', ''));
                });
            })
            ->orderBy(
                sanitize_text_field(Arr::get($request, 'order_by', 'id')),
                sanitize_text_field(Arr::get($request, 'order_type', 'DESC'))
            );

        if (isset($request['customer_id'])) {
            $customerId = (int)$request['customer_id'];
            $ordersQuery = $ordersQuery->search(["customer_id" => ["column" => "customer_id", "value" => $customerId]]);
        }

        if (!empty($request['filters'])) {
            $ordersQuery = $ordersQuery->applyCustomFilters(Arr::get($request, 'filters', []));
        }

        if (!empty($request['paginate'])) {
            $ordersQuery = $ordersQuery->paginate(Arr::get($request['paginate'], 'per_page'), ['*'], 'page', Arr::get($request['paginate'], 'current_page'));
        }
        if (empty($request['paginate'])) {
            $ordersQuery = $ordersQuery->get();
        }

        $orders = $ordersQuery;

        foreach ($orders as $order) {
            $rowData = [
                $order->id,
                $order->status,
                // $order->shipping_status,
                $order->currency,
                Helper::toDecimal($order->total_amount, false),
                count($order->order_items),
                $order->type,
                $order->payment_method,
                $order->created_at,
                $order->customer_id,
                $order->customer ? $order->customer->full_name : '',
                $order->customer ? $order->customer->email : ''
            ];
            $writer->addRow(WriterEntityFactory::createRowFromArray($rowData));
        }
        $writer->close();
        die();
    }

    public function exportRepeatCustomers()
    {
        if (!current_user_can('manage_options')) {
            die('No Access');
        }

        Helper::loadSpoutLib();

        $writer = WriterEntityFactory::createCSVWriter();

        $writer->openToBrowser('repeat-customers-export-' . gmdate('Y-m-d_H:i') . '.csv');

        $values = [
            'Customer ID',
            'Customer Name',
            'Customer Email',
            'Total Orders',
            'Order Status'
        ];

        $rowFromValues = WriterEntityFactory::createRowFromArray($values);
        $writer->addRow($rowFromValues);

        $params = App::getInstance()->request->get('params', []);

        $repeatCustomers = CustomerHelper::getRepeatCustomerBySearch($params)->paginate(Arr::get($params, 'per_page'), ['*'], 'page', Arr::get($params, 'current_page'));

        foreach ($repeatCustomers as $customer) {
            $rowData = [
                $customer->id,
                $customer->full_name ? $customer->full_name : '',
                $customer->email ? $customer->email : '',
                count($customer->orders),
                Arr::get($customer, 'orders.0.status', '')
            ];
            $writer->addRow(WriterEntityFactory::createRowFromArray($rowData));
        }
        $writer->close();
        die();
    }

    public function exportProducts()
    {
        if (!current_user_can('manage_options')) {
            die('No Access');
        }

        Helper::loadSpoutLib();

        $writer = WriterEntityFactory::createCSVWriter();

        $writer->openToBrowser('products-export-' . gmdate('Y-m-d_H:i') . '.csv');

        $values = [
            'ID',
            'Image',
            'Product Name',
            'Type',
            'Variation',
            'Min Price',
            'Max Price',
            'Stock',
            'Status',
            'Created Date',
        ];

        $rowFromValues = WriterEntityFactory::createRowFromArray($values);
        $writer->addRow($rowFromValues);

        $request = App::getInstance()->request->all();

        $params = [
            "select" => ['ID', 'post_title', 'post_date', 'post_status', 'guid'],
            "with" => ['detail'],
            "admin_all_statuses" => ["post_status" => ["column" => "post_status", "operator" => "in", "value" => Status::productAdminAllStatuses()]],
            "admin_search" => Arr::get($request, 'search'),
            "admin_filters" => Arr::get($request, 'filters', []),
            "order_by" => Arr::get($request, 'order_by', 'ID'),
            "order_type" => Arr::get($request, 'order_type', 'DESC'),
            "per_page" => Arr::get($request, 'paginate.per_page', 10),
            "page" => Arr::get($request, 'paginate.current_page'),
        ];

        $products = ProductResource::get($params)['products'];

        foreach ($products as $product) {

            $rowData = [
                $product->ID,
                $product->thumbnail,
                //Image Check Test
                //(new ProductAdminHelper())->getFeaturedMedia($product->detail->featured_media),
                $product->post_title,
                ucfirst($product->detail->fulfillment_type),
                $product->detail->variation_type,
                Helper::toDecimal($product->detail->min_price),
                Helper::toDecimal($product->detail->max_price),
                $product->detail->stock_availability,
                ucfirst($product->post_status),
                gmdate('Y-m-d', strtotime($product->post_date)),
            ];
            $writer->addRow(WriterEntityFactory::createRowFromArray($rowData));
        }
        $writer->close();
        die();
    }
}
