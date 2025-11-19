<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\Resource\FrontendResource\OrderDownloadPermissionResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\Api\Resource\ProductDownloadResource;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\OrderDownloadPermission;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\DateTime;

class CustomerHelper
{
    /**
     * @return array
     * @param array $customer with details info like email, name
     */
    public function sanitizeCustomer($customer)
    {
        $email = sanitize_email(Arr::get($customer, 'email', ''));
        foreach ($customer as $key => $val) {
            $customer[$key] = sanitize_text_field($val);
        }
        $customer['email'] = $email;
        return $customer;
    }


    /**
     * @return Boolean
     * 
     * Check email is already available or not
     */

    /**
     * @return Boolean Check the request owner has right permission 
     * or throw new \Exception('You don\'t have permission!')
     */


    public function updateCustomer($id, $data)
    {
        return Customer::findOrFail($id)
            ->update(
                $this->sanitizeCustomer($data)
            );
    }

    public function createWpUser($newCustomer)
    {
        $userId = wp_create_user(
            Arr::get($newCustomer, 'first_name'), 
            wp_generate_password(8),
            Arr::get($newCustomer, 'email')
        );

        if (is_wp_error($userId)) {
            $error = $userId->get_error_message();
            throw new \Exception(esc_html($error));
        } else {
            return $userId;
        }
    }

    //Using Resource Api
    /*public function manageCustomer($selected)
    {
        sleep(1);
        $action = sanitize_text_field(Arr::get($selected, 'action', ''));
        $customerIds = Arr::get($selected, 'customer_ids', []);

        $customerIds = array_map(function ($id) {
            return (int)$id;
        }, $customerIds);

        $customerIds = array_filter($customerIds);
        if (!$customerIds) {
            throw new \Exception(__('Customers selection is required', 'fluent-cart'));
        }

        $customersApi = new Customers();

        if ($action == 'delete_customers') {
            return $customersApi->delete($customerIds);
        }

        if ($action == 'change_customer_status') {
            return $customersApi->updateStatus($selected);
        }

        throw new \Exception(__('Selected action is invalid', 'fluent-cart'));
    }*/

    public static function getRepeatCustomerBySearch($params)
    {
       $params["status"] = [ "column" => "status", "operator" => "in", "value" => [Arr::get($params, 'order_status')] ];
    //    $params["payment_status"] = [ "column" => "payment_status", "operator" => "in", "value" => Status::getTransactionSuccessStatuses() ];
       $param = Arr::only($params, ['created_at', 'status', 'payment_status']);
        
        return Customer::query()
            ->when($params["search"], function ($query) use ($params) {
                return $query->search($params["search"]);
            })
            ->whereHas('orders', function ($query) use($param){
                $query->when($param, function ($query) use ($param) {
                    return $query->search($param);
                })->havingRaw('COUNT(*) > 1');
            })
            ->with(['orders' => function ($query) use($param){
                $query->select('id', 'customer_id', 'status')
                ->when($param, function ($query) use ($param) {
                    return $query->search($param);
                });
            }]);
    }

    public static function checkDownloadPermissionAndStoreLog($params)
    {
        if (!is_user_logged_in()) {
            wp_send_json(['message' => __('You are not logged in', 'fluent-cart')], 422);
        }

        $orderUuid = Arr::get($params, 'order');
        $downloadId = Arr::get($params, 'download_id');
        $variationId = Arr::get($params, 'variation_id');
        $order = OrderResource::find($orderUuid);
        
        if (!empty($order)) {

            if (empty($order->customer) || wp_get_current_user()->ID != $order->customer->user_id) {
                wp_send_json(['message' => __('Sorry, This is not your order', 'fluent-cart')], 422);
            }

            $orderId = Arr::get($order, 'id');
        
            $productDownload = ProductDownloadResource::find($downloadId);

            if (!empty($productDownload)) {

                $productDownloadId = Arr::get($productDownload, 'id');
                $productDownloadLimit = Arr::get($productDownload, 'settings.download_limit', '');
                $productDownloadExpiry = Arr::get($productDownload, 'settings.download_expiry', '');

                $hasDownloaded = OrderDownloadPermissionResource::find($downloadId, ['order_id' => $orderId, 'variation_id' => $variationId]);

                $totalDownloads = Arr::get($hasDownloaded, 'download_count', 0);
                
                $downloadExpiryDate = '';

                if($productDownloadExpiry !== '') {
                    $downloadExpiryDate = new DateTime($order->created_at);

                    $downloadExpiryDate->modify('+'. $productDownloadExpiry .' months')->format('Y-m-d H:i:s');
                }
                $currentDate = gmdate('Y-m-d H:i:s', current_time('timestamp'));

                if ($productDownloadExpiry !== '' && $currentDate > $downloadExpiryDate) {
                    wp_send_json(['message' => __('Download date has been expired', 'fluent-cart')], 422);
                }

                if($productDownloadLimit !== '' && $totalDownloads >= $productDownloadLimit) {
                    wp_send_json(['message' => __('You have crossed download limit', 'fluent-cart')], 422);
                }

                if(($totalDownloads < $productDownloadLimit && $currentDate <= $downloadExpiryDate) || ($productDownloadLimit === '' && $productDownloadExpiry === '') || ($productDownloadLimit === '' && $currentDate <= $downloadExpiryDate) || (($totalDownloads < $productDownloadLimit && $productDownloadExpiry === ''))) {
                    $data= [
                        'order_id' => $orderId,
                        'variation_id' => $variationId,
                        'customer_id' => Arr::get($order, 'customer.id'),
                        'download_id' => $downloadId,
                        'download_count' => $totalDownloads += 1,
                        'download_limit' => $productDownloadLimit,
                        'access_expires' => $downloadExpiryDate,
                    ];
                    return self::storeDownloadLog($hasDownloaded, $data);
                }

            }
            wp_send_json(['message' => __('File not found', 'fluent-cart')], 422);
        }
        wp_send_json(['message' => __('Order not found', 'fluent-cart')], 422);
    }

    private static function storeDownloadLog(OrderDownloadPermission $orderDownloadPermission = null, $data)
    {
        if (empty($orderDownloadPermission)) {
            return OrderDownloadPermissionResource::create($data);
        }
        return OrderDownloadPermissionResource::update($data, Arr::get($orderDownloadPermission, 'id'));   
    }

    // public static function storeDownloadLog($params)
    // {
    //     if (!is_user_logged_in()) {
    //         wp_send_json_error(['message' => __('You are not logged in', 'fluent-cart')], 423);
    //     }

    //     $order = Arr::get($params, 'order');
    //     $downloadId = Arr::get($params, 'download_id');

    //     $order = OrderResource::find($order, [
    //         'with' => [
    //             'order_items' => function ($query) use ($downloadId) {
    //                 return $query->with(['product_downloads'])
    //                     ->whereHas('product_downloads', function ($query) use ($downloadId) {
    //                         $query->where('id', $downloadId);
    //                     });
    //             }
    //         ]
    //     ]);

    //     if ($order) {

    //         if (empty($order->customer) || wp_get_current_user()->ID != $order->customer->user_id) {
    //             wp_send_json_error(['message' => __('Sorry, This is not your order', 'fluent-cart')], 423);
    //         }
    //         $orderId = Arr::get($order, 'id');
    //         $orderItem = $order->order_items->first(); 
    //         if ($orderItem) {
    //             $productDownloadId = Arr::get($orderItem, 'product_downloads.id');
    //             $hasDownload = OrderDownloadPermissionResource::find($productDownloadId, ['order_id' => $orderId ]);
    //             $downloadCount = Arr::get($hasDownload, 'download_count', 0) + 1;

    //             $data= [
    //                 'order_id' => $orderId,
    //                 'customer_id' => Arr::get($order, 'customer.id'),
    //                 'download_id' => $productDownloadId,
    //                 'download_count' => $downloadCount,
    //                 'download_limit' => Arr::get($orderItem, 'product_downloads.settings.download_limit'),
    //                 'access_expires' => Arr::get($orderItem, 'product_downloads.settings.download_expiry'),
    //             ];
        
    //             if (empty($hasDownload)) {
    //                 $isCreatedOrUpdated = OrderDownloadPermissionResource::create($data);
    //             }
    //             else { 
    //                 $isCreatedOrUpdated = OrderDownloadPermissionResource::update($data, Arr::get($hasDownload, 'id'));
    //             }
        
    //             if (is_wp_error($isCreatedOrUpdated)) {
    //                 return $isCreatedOrUpdated;   
    //             }
        
    //             wp_send_json($isCreatedOrUpdated, 200);
    //         }
    //     }
    // }

    public function calculateCustomerStats(array $customerIds = [])
    {
        $customers = [];
        if(count($customerIds) > 0) {
            $customers = Customer::query()->whereIn('id', $customerIds)->with('orders.transactions')->get();
        } else {
            $customers = Customer::query()->with('orders.transactions')->get();
        }

        $updateableData = [];

        foreach ($customers as $customer){
            $orders = $customer->orders;
            $totalPayments = [];
            foreach ($orders as $order){
                foreach ($order->transactions as  $transaction){
                    if($transaction->status == Status::TRANSACTION_SUCCEEDED){
                        if(empty($totalPayments[$order['currency']])){
                            $totalPayments[$order['currency']]=$transaction['total'];
                        }else{
                            $totalPayments[$order['currency']]+=$transaction['total'];
                        }
                    }
                }
            }
            $updateableData[] = [
                'id' => $customer->id,
                'purchase_value' => json_encode($totalPayments),
                'purchase_count' => $orders->count(),
                'first_purchase_date' => $orders->min('created_at').'',
                'last_purchase_date' => $orders->max('created_at').''
            ];
        }
        if(!empty($customers)) {
            return Customer::query()->batchUpdate($updateableData);
        }
    }

}
