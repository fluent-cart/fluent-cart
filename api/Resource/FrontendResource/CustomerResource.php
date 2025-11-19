<?php

namespace FluentCart\Api\Resource\FrontendResource;

use FluentCart\Api\Resource\BaseResourceApi;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class CustomerResource extends BaseResourceApi
{


    public static function getQuery(): Builder
    {
        return Customer::query();
    }

    /**
     * Get customers based on specified parameters.
     *
     * @param array $params Array containing the necessary parameters.
     *   [
     *     "params" => (array) Required.
     *          [
     *              'search'       => (string) Optional. Search Customer.
     *                  [
     *                      "column name(e.g., first_name|last_name|email|id)" => [
     *                          column => "column name(e.g., first_name|last_name|email|id)",
     *                          operator => "operator (e.g., like_all|rlike|or_rlike|or_like_all)",
     *                          value => "value" ]
     *                  ],
     *              'filters'      => (string) Optional. Filters customer.
     *                  [
     *                      "column name(e.g., first_name|last_name|email)" => [
     *                          column => "column name(e.g., first_name|last_name|email)",
     *                          operator => "operator (e.g., between|or_between|like_all|in)",
     *                          value => "value" ]
     *                  ],
     *              'order_by'     => (string) Optional. Column to order by,
     *              'order_type'   => (string) Optional. Order type for sorting (ASC or DESC),
     *              'per_page'     => (int) Optional. Number of items for per page,
     *              'page'         => (int) Optional. Page number for pagination
     *          ]
     *   ]
     *
     */
    public static function get(array $params = [])
    {
        return static::getQuery()->when(Arr::get($params["params"], 'search'), function ($query) use ($params) {
            return $query->search(Arr::get($params["params"], 'search', ''));
        })
            ->applyCustomFilters(Arr::get($params["params"], 'filters', []))
            ->orderBy(
                sanitize_sql_orderby(Arr::get($params["params"], 'order_by', 'id')),
                sanitize_sql_orderby(Arr::get($params["params"], 'order_type', 'DESC')))
            ->paginate(Arr::get($params["params"], 'per_page', 15), ['*'], 'page', Arr::get($params["params"], 'page'));
    }

    /**
     * Find customer by ID.
     *
     * @param int $id Required. The ID of the customer.
     * @param array $params Optional. Additional parameters for finding a customer.
     *        [
     *           'with' => (array) Optional.Relationship name to be eagerly loaded,
     *        ]
     *
     */
    public static function find($id, $params = [])
    {
        $with = Arr::get($params, 'with', []);
        $customer = static::getQuery()->with($with)->findOrFail($id);
        return [
            'customer' => $customer
        ];
    }

    /**
     * Create a new customer with the given data
     *
     * @param array $data Required. Array containing the necessary parameters
     *        [
     *              'first_name'  => (string) Required. The first name of the customer,
     *              'last_name'   => (string) Optional. The last name of the customer,
     *              'email'       => (string) Required. The email of the customer,
     *              'city'        => (string) Optional. The city of the customer,
     *              'state'       => (string) Optional. The state of the customer,
     *              'postcode'    => (string) Optional. The postal code of the customer,
     *              'country'     => (string) Optional. The country of the customer,
     *              'wp_user'     => (string) Optional. Create customer as WP user,
     *        ]
     * @param array $params Optional. Additional parameters for creating a customer.
     *
     */
    public static function create($data, $params = [])
    {
        $email = Arr::get($data, 'email');

        $customer = static::getQuery()->firstOrCreate(
            ['email' => $email],
            $data
        );

        if ($customer) {
            return static::makeSuccessResponse(
                $customer,
                __('Customer created successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Customer creation failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Update customer with the given data
     *
     * @param array $data Required. Array containing the necessary parameters
     *        [
     *              'first_name'  => (string) Required. The first name of the customer,
     *              'last_name'   => (string) Optional. The last name of the customer,
     *              'email'       => (string) Required. The email of the customer,
     *              'city'        => (string) Optional. The city of the customer,
     *              'state'       => (string) Optional. The state of the customer,
     *              'postcode'    => (string) Optional. The postal code of the customer,
     *              'country'     => (string) Optional. The country of the customer,
     *        ]
     * @param int $id Required. The ID of the customer.
     * @param array $params Optional. Additional parameters for creating a customer.
     *
     */
    public static function update($data, $id, $params = [])
    {
        $customer = static::getQuery()->findOrFail($id)->update($data);

        if ($customer) {
            return static::makeSuccessResponse(
                $customer,
                __('Customer updated successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Customer update failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Delete a customer based on the given ID and parameters.
     *
     * @param int $id Optional. The ID of the customer.
     * @param array $params Optional. Additional parameters for deleting multiple customers.
     *        [
     *            'ids' => (array) Required. The array of customer IDs to be deleted.
     *        ]
     *
     */
    public static function delete($id, $params = [])
    {
        $ids = Arr::get($params, 'ids');

        $customers = static::getQuery()->with(['orders'])->whereIn('id', $ids)->get();

        foreach ($customers as $customer) {
            $customer->orders()->delete();
            $customer->delete();
        }

        if ($customer) {
            return static::makeSuccessResponse(
                '',
                __('Selected Customers has been deleted permanently', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Customer update failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Get orders for a specific customer.
     *
     * @param array $data Required. Array containing the necessary parameters for order retrieval.
     *        [
     *           'per_page' => (int) Optional. Number of items for per page,
     *        ]
     * @param int $customerId Required. The ID of the customer for whom orders are being retrieved.
     *
     */
    public static function getOrders(array $data, $customerId): array
    {
        return OrderResource::search(['customer_id' => ['column' => 'customer_id', 'value' => $customerId]], function (\FluentCart\Framework\Database\Orm\Builder $query) use ($data) {
            return $query->orderBy('id', 'DESC')
                ->paginate(Arr::get($data, 'per_page', 15));
        });
    }

    /**
     * Update the status of multiple customers with the given parameters.
     *
     * @param array $params Optional. Array containing the necessary parameters
     *        [
     *           'new_status'    => (string) Required. The new status to be set for the customers.
     *           'customer_ids'  => (array) Required. Customer IDs whose status will be updated.
     *        ]
     *
     */
    public static function updateStatus($params = [])
    {
        $newStatus = Arr::get($params, 'new_status', '');

        if (!$newStatus) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Please select status', 'fluent-cart')]
            ]);
        }

        $validStatuses = Status::getEditableCustomerStatuses();
        if (!isset($validStatuses[$newStatus])) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Provided customer status is not valid', 'fluent-cart')]
            ]);
        }

        $customers = static::getQuery()->with(['orders'])->whereIn('id', Arr::get($params, 'customer_ids'))->get();

        foreach ($customers as $customer) {
            $customer->updateCustomerStatus($newStatus);
        }

        return static::makeSuccessResponse(
            '',
            __('Customer Status has been changed', 'fluent-cart')
        );
    }

    /**
     * Manage customers based on the provided action and customer IDs.
     *
     * @param array $params Optional. Array containing the necessary parameters
     *        [
     *           'action' => (string) Required. The action to be performed on the selected customers.
     *                       (e.g., Possible values: 'delete_customers', 'change_customer_status')
     *           'customer_ids'  => (array) Required. Customer IDs whose action will be performed.
     *        ]
     *
     */
    public static function manageCustomer($params = [])
    {

        $action = Arr::get($params, 'action', '');
        $customerIds = Arr::get($params, 'customer_ids', []);

        $customerIds = array_map(function ($id) {
            return (int)$id;
        }, $customerIds);


        $customerIds = array_filter($customerIds);

        if (!$customerIds) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Customers selection is required', 'fluent-cart')]
            ]);
        }

        if ($action == 'delete_customers') {
            return static::deleteAuthorized(null, ['ids' => $customerIds]);
        }

        if ($action == 'change_customer_status') {
            return static::updateStatusAuthorized($params);
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Selected action is invalid', 'fluent-cart')]
        ]);
    }
}