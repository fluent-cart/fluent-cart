<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Collection;
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
     *              'search' => (string) Optional.Search Customer.
     *                  [
     *                      "column name(e.g., first_name|last_name|email|id)" => [
     *                          column => "column name(e.g., first_name|last_name|email|id)",
     *                          operator => "operator (e.g., like_all|rlike|or_rlike|or_like_all)",
     *                          value => "value" ]
     *                  ],
     *              'filters'      => (string) Optional.Filters customer.
     *                  [
     *                      "column name(e.g., first_name|last_name|email)" => [
     *                          column => "column name(e.g., first_name|last_name|email)",
     *                          operator => "operator (e.g., between|or_between|like_all|in)",
     *                          value => "value" ]
     *                  ],
     *              'order_by'     => (string) Optional. Column to order by,
     *              'order_type' => (string) Optional. Order type for sorting (ASC or DESC),
     *              'per_page' => (int) Optional. Number of items for per page,
     *              'page' => (int) Optional. Page number for pagination
     *          ]
     *   ]
     *
     */
    public static function get(array $params = [])
    {
        $sortBy = Arr::get($params, 'sort_by', 'id');
        $sortType = Arr::get($params, 'sort_type', 'DESC');
        $search = Arr::get($params, 'search', '');

        return static::getQuery()->when($search, function ($query) use ($search) {
            return $query->searchBy($search);
        })
            ->applyCustomFilters(Arr::get($params, 'filters', []))
            ->orderBy(
                sanitize_sql_orderby($sortBy),
                sanitize_sql_orderby($sortType))
            ->paginate(Arr::get($params, 'per_page', 15), ['*'], 'page', Arr::get($params, 'page'));
    }

    /**
     * Find customer by ID.
     *
     * @param int $id Required. The ID of the customer.
     * @param array $params Optional. Additional parameters for finding a customer.
     *        [
     *           'with' => (array) Optional. Relationships name to be eager loaded,
     *        ]
     *
     */
    public static function find($id, $params = [])
    {
        $with = Arr::get($params, 'with', []);
        $customer = Customer::with($with)->find($id);
        if (!empty($customer) && isset($customer['labels'])) {
            $customer['selected_labels'] = Collection::make($customer['labels'])->pluck('label_id');
        }

        return [
            'customer' => (!empty($customer) ? $customer : null)
        ];
    }

    public static function findOrder($id, $params = [])
    {
        $customer = Customer::with('orders.filteredOrderItems')->find($id);

        return [
            'data' => (!empty($customer) ? $customer->orders : null)
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
        $fullName = trim(Arr::get($data, 'full_name', ''));
        $nameParts = AddressHelper::guessFirstNameAndLastName($fullName);

        $data['first_name'] = Arr::get($nameParts, 'first_name', '');
        $data['last_name'] = Arr::get($nameParts, 'last_name', '');

        $data['purchase_value'] = [];
        $customer = static::getQuery()->firstOrCreate(
            ['email' => $email],
            $data
        );

        if (empty($customer)) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Customer creation failed.', 'fluent-cart')]
            ]);
        }

        $isUserAttached = false;
        $user = get_user_by('email', $email);
        if ($user) {
            $customer->update(['user_id' => $user->ID]);
            $isUserAttached = true;
        }

        if (Arr::get($data, 'wp_user') === 'yes' && !$isUserAttached) {
            $isUserCreated = \FluentCart\App\Services\AuthService::createUserFromCustomer($customer);
            if (is_wp_error($isUserCreated)) {
                return static::makeErrorResponse([
                    ['code' => 423, 'message' => __('Failed to create user.', 'fluent-cart')]
                ]);
            }
        }

        if ($customer->wasRecentlyCreated) {
            return static::makeSuccessResponse(
                $customer,
                __('Customer created successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Customer already exists.', 'fluent-cart')]
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
        $customer = static::getQuery()->find($id);
        $fullName = trim(Arr::get($data, 'full_name', ''));

        if ($customer) {
            $nameParts = AddressHelper::guessFirstNameAndLastName($fullName);

            $data['first_name'] = Arr::get($nameParts, 'first_name', '');
            $data['last_name'] = Arr::get($nameParts, 'last_name', '');

            if ($customer->user_id != 0) {
                $data['email'] = $customer->email;
                $isUserUpdated = static::updateUser($data, $customer->user_id);

                if (is_wp_error($isUserUpdated)) {
                    return static::makeErrorResponse([
                        ['code' => 423, 'message' => __('Failed to update user.', 'fluent-cart')]
                    ]);
                }
            }
            $customer->update($data);
            $customer->refresh();

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

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Customer not found, please reload the page and try again!', 'fluent-cart')]
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
     * Update customer additional information with the given data
     *
     * @param array $data Required. Array containing the necessary parameters
     *        [
     *              'labels'  => (array) Required. The id of the labels,
     *        ]
     * @param int $id Required. The ID of the customer.
     * @param array $params Optional. Additional parameters for updating a customer info.
     *
     */
    public static function updateAdditionalInfo($data, $id, $params = [])
    {
        $customer = static::find($id, ['with' => ['labels']]);
        $customer = $customer['customer'];

        if ($customer) {
            $newLabelIds = Arr::get($data, 'labels', []);
            // Pluck and convert $existingLabelIds to a collection of strings
            $existingLabelIds = Collection::make($customer['labels'])->pluck('label_id')->map(function ($value) {
                return (string)$value;
            });

            if (count($newLabelIds) > 0 || count($existingLabelIds) > 0) {
                $isUpdated = LabelResource::addLabelToLabelRelationships($customer, [
                    'labelable_id'       => $id,
                    'labelable_type'     => Customer::class,
                    'new_label_ids'      => $newLabelIds,
                    'existing_label_ids' => $existingLabelIds
                ]);

                if ($isUpdated) {
                    return static::makeSuccessResponse(
                        $isUpdated,
                        __('Customer updated successfully!', 'fluent-cart')
                    );
                }

                return static::makeErrorResponse([
                    ['code' => 400, 'message' => __('Customer update failed.', 'fluent-cart')]
                ]);
            }

            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Customer do not have any changes to update.', 'fluent-cart')]
            ]);
        }

        return static::makeErrorResponse([
            ['code' => 404, 'message' => __('Customer not found, please reload the page and try again!', 'fluent-cart')]
        ]);
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
            return static::delete(null, ['ids' => $customerIds]);
        }

        if ($action == 'change_customer_status') {
            return static::updateStatus($params);
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Selected action is invalid', 'fluent-cart')]
        ]);
    }

    public static function getCurrentCustomer(bool $createIfNotExists = false): ?object
    {
        static $cachedCustomer = null;

        if ($cachedCustomer !== null) {
            return $cachedCustomer;
        }

        if (!is_user_logged_in()) {
            return null;
        }

        $currentUser = get_user_by('ID', get_current_user_id());

        // Try to get the existing customer
        $query = Customer::query()->where('user_id', $currentUser->ID)
            ->orWhere('email', $currentUser->user_email)
            ->with(['billing_address', 'shipping_address']);


        $existingCustomer = $query->first();

        // Return if found
        if ($existingCustomer) {
            if ($existingCustomer->user_id != $currentUser->ID) {
                // Update the user_id if it doesn't match
                $existingCustomer->user_id = $currentUser->ID;
                $existingCustomer->save();
            }

            $cachedCustomer = $existingCustomer;
            return $existingCustomer;
        }

        if (!$createIfNotExists) {
            return null;
        }

        $userId = $currentUser->ID;

        $appRequestData = App::request()->all();

        $customer = Customer::query()->create([
            'first_name' => $currentUser->first_name,
            'last_name'  => $currentUser->last_name,
            'email'      => $currentUser->user_email,
            'user_id'    => $userId,
            'country'    => Arr::get($appRequestData, 'country', ''),
            'city'       => Arr::get($appRequestData, 'city', ''),
            'state'      => Arr::get($appRequestData, 'state', ''),
            'postcode'   => Arr::get($appRequestData, 'postcode', ''),
        ]);

        // get customer by id
        $cachedCustomer = static::getQuery()
            ->where('id', $customer->id)
            ->with(['billing_address', 'shipping_address'])
            ->first();

        return $cachedCustomer;

    }

    private static function updateUser($data, $userId)
    {
        $firstName = sanitize_text_field(Arr::get($data, 'first_name'));
        $lastName = sanitize_text_field(Arr::get($data, 'last_name'));
        $name = trim($firstName . ' ' . $lastName);
        $email = sanitize_email(Arr::get($data, 'email', ''));

        if (!$name) {
            return false;
        }

        $data = array_filter([
            'ID'            => $userId,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'nickname'      => $name,
            'user_nicename' => $name,
            'display_name'  => $name,
            'user_url'      => Arr::get($data, 'user_url'),
        ]);

        $allowEmailUpdate = current_user_can('manage_options');

        if (!$allowEmailUpdate) {
            $currentUser  = wp_get_current_user();
            $currentEmail = strtolower($currentUser->user_email);

            $targetUser   = get_userdata($userId);
            $targetEmail  = $targetUser ? strtolower($targetUser->user_email) : null;

            // Non-admin: allow only if editing own account
            if ($currentEmail && $currentEmail === $targetEmail) {
                $allowEmailUpdate = true;
            }
        }

        if ($allowEmailUpdate) {
            $data['user_email'] = $email;
            $data['user_login'] = $email;
        }

        // Update basic user data
        $result = wp_update_user($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result;

    }

}
