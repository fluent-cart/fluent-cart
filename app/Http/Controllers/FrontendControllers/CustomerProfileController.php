<?php

namespace FluentCart\App\Http\Controllers\FrontendControllers;

//use FluentCart\Api\Resource\FrontendResource\CustomerResource;
use FluentCart\Api\Resource\CustomerAddressResource;
use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\FrontendResource\OrderResource;
use FluentCart\Api\Resource\OrderDownloadPermissionResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Http\Requests\FrontendRequests\CustomerRequests\CustomerProfileAccountDetailsRequest;
use FluentCart\App\Http\Requests\FrontendRequests\CustomerRequests\CustomerProfileRequest;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\FileSystem\FileManager;
use FluentCart\App\Services\FrontendView;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\PlanUpgradeService;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Relations\HasMany;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCartPro\App\Hooks\Handlers\UpgradeHandler;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseActivation;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;


class CustomerProfileController extends BaseFrontendController
{

    /**
     * Handle the request to retrieve the customer's orders.
     *
     * This method checks if the user is logged in, retrieves the current customer,
     * and searches for their orders based on the provided search parameters.
     *
     * @param Request $request The incoming HTTP request.
     * @return array
     */
    public function index(Request $request)
    {
        $customer = CustomerResource::getCurrentCustomer();


        if (!$customer) {
            return apply_filters('fluent_cart/customer_dashboard_data', [
                'message'        => __('Success', 'fluent-cart'),
                'dashboard_data' => [
                    'orders' => []
                ],
                'sections_parts' => [
                    'before_orders_table' => '',
                    'after_orders_table'  => ''
                ]
            ], [
                'customer' => null
            ]);
        }


        $orders = Order::query()
            ->with(['order_items' => function ($query) {
                $query->select('order_id', 'post_title', 'title', 'quantity', 'payment_type');
            }])
            ->where('customer_id', $customer->id)
            ->where(function ($query) {
                $query
                    ->where(function ($query) {
                        $query->where('parent_id', '')->orWhereNull('parent_id');
                    })
                    ->orWhere('type', '!=', 'renewal');
            })
            ->withCount('renewals')
            ->orderBy('created_at', 'DESC')
            ->limit(5)
            ->get();

        $orders = $orders->map(function ($order) {
            return [
                'created_at'     => $order->created_at->format('Y-m-d H:i:s'),
                'invoice_no'     => $order->invoice_no,
                'total_amount'   => $order->total_amount,
                'uuid'           => $order->uuid,
                'type'           => $order->type,
                'status'         => $order->status,
                'renewals_count' => $order->renewals_count,
                'order_items'    => $order->order_items->map(function ($item) {
                    return [
                        'post_title'   => $item->post_title,
                        'title'        => $item->title,
                        'quantity'     => $item->quantity,
                        'payment_type' => $item->payment_type,
                    ];
                }),
            ];
        });

        return apply_filters('fluent_cart/customer_dashboard_data', [
            'message'        => __('Success', 'fluent-cart'),
            'dashboard_data' => [
                'orders' => $orders
            ],
            'sections_parts' => [
                'before_orders_table' => '',
                'after_orders_table'  => ''
            ]
        ], [
            'customer' => $customer
        ]);
    }

    public function getCustomerProfileDetails()
    {
        // Get the current logged-in customer
        $customer = CustomerResource::getCurrentCustomer();

        if (!$customer) {
            // get current user by id
            $userId = get_current_user_id();
            $currentUser = get_user_by('ID', $userId);

            // get user first_name and last_name from usermeta
            $currentUser->data->first_name = get_user_meta($userId, 'first_name', true);
            $currentUser->data->last_name = get_user_meta($userId, 'last_name', true);


            return $this->sendSuccess([
                'message' => __('Success', 'fluent-cart'),
                'data'    => [
                    'first_name'       => $currentUser->data->first_name,
                    'last_name'        => $currentUser->data->last_name,
                    'user_login'       => $currentUser->data->user_login,
                    'user_email'       => $currentUser->data->user_email,
                    'email'            => $currentUser->data->user_email,
                    'user_nicename'    => $currentUser->data->user_nicename,
                    'display_name'     => $currentUser->data->display_name,
                    'billing_address'  => [],
                    'shipping_address' => [],
                    'not_a_customer'   => true
                ]
            ]);
        }

        // Fetch the customer along with the related WordPress user
        $customerData = Customer::query()
            ->where('id', $customer->id)
            ->with(['billing_address', 'shipping_address'])
            ->first()
            ->toArray();

        $userData = Arr::only($customerData, [
            'first_name',
            'last_name',
            'email',
            'billing_address',
            'shipping_address'
        ]);
        // Combine the customer and WordPress user data in the response
        return $this->sendSuccess([
            'message' => __('Success', 'fluent-cart'),
            'data'    => $userData
        ]);
    }

    public function updateCustomerProfileDetails(CustomerProfileAccountDetailsRequest $request): \WP_REST_Response
    {
        $errorResponse = $this->checkUserLoggedIn();

        // Check if there is an error response and return it if exists
        if ($errorResponse !== null) {
            return $errorResponse;
        }
        // Get the current logged-in customer
        $customer = CustomerResource::getCurrentCustomer();

        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart')
            ]);
        }

        // Validate the request data for customer and addresses
        $validatedData = $request->getSafe($request->sanitize());
        // Update the customer with the validated data
        $customer->first_name = Arr::get($validatedData, 'first_name');
        $customer->last_name = Arr::get($validatedData, 'last_name');
        $customerUpdate = $customer->save();

        if (is_wp_error($customerUpdate)) {
            return $this->sendError([
                'message' => $customerUpdate->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Profile updated successfully', 'fluent-cart'),
        ]);
    }

    /**
     * Helper method to validate email uniqueness
     */
    private function validateEmailUniqueness($email, $currentCustomerId): ?\WP_REST_Response
    {
        $customerId = $currentCustomerId;
        $userId = Customer::query()->find($customerId)->user_id;

        // Fetch the current customer's email to compare
        $currentEmail = Customer::query()->find($customerId)->email;

        // Check if the email is different from the current user's email and exists in WordPress users table
        if ($email !== $currentEmail) {
            // Check if the email exists in the WordPress users table
            $emailExistsInWp = get_user_by('email', $email);

            if ($emailExistsInWp && $emailExistsInWp->ID !== $userId) {
                return $this->sendError([
                    'message' => __('The email address is already in use in WordPress users.', 'fluent-cart')
                ]);
            }
        }

        // Check if the email exists in the Customer table, excluding the current customer
        $emailExistsInCustomer = Customer::query()->where('email', $email)->where('id', '!=', $customerId)->exists();

        if ($emailExistsInCustomer) {
            return $this->sendError([
                'message' => __('The email address is already in use in the customer records.', 'fluent-cart')
            ]);
        }

        return null; // Return null mean email is unique
    }

    public function createCustomerProfileAddress(CustomerProfileRequest $request): \WP_REST_Response
    {
        // Get the current logged-in customer
        $customer = CustomerResource::getCurrentCustomer(true);

        // Sanitize and retrieve the request data
        $data = $request->getSafe($request->sanitize());

        // Attempt to create a new address for the logged-in customer
        $isCreated = CustomerAddressResource::create(
            $data,
            ['id' => $customer->id]
        );

        // Check if there was an error during the creation process, return the error if one occurred
        if (is_wp_error($isCreated)) {
            return $this->sendError([
                'message' => $isCreated->get_error_message()
            ]);
        }

        // Return the success response
        return $this->response->sendSuccess($isCreated);

    }

    public function updateCustomerProfileAddress(CustomerProfileRequest $request): \WP_REST_Response
    {
        // Call the method and store the response
        $errorResponse = $this->checkUserLoggedIn();

        // Check if there is an error response and return it if exists
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        // Get the current logged-in customer
        $customer = CustomerResource::getCurrentCustomer();

        // Sanitize and retrieve the request data
        $data = $request->getSafe($request->sanitize());

        // Retrieve the address ID from the request
        $id = $request->getSafe('id', 'intval');


        $address = CustomerAddresses::query()->findOrFail($id);

        if ($address->customer_id != $customer->id) {
            return $this->sendError([
                'message' => __('You are not authorized to update this address', 'fluent-cart')
            ]);
        }

        // Proceed with the update since IDs match
        $isUpdated = CustomerAddressResource::update($data, $id);

        // Check for errors during the update process
        if (is_wp_error($isUpdated)) {
            return $this->sendError([
                'message' => $isUpdated->get_error_message()
            ]);
        }

        // Return the success response
        return $this->response->sendSuccess($isUpdated);

    }

    public function makePrimaryCustomerProfileAddress(Request $request): \WP_REST_Response
    {
        // Call the method and store the response
        $errorResponse = $this->checkUserLoggedIn();
        // Check if there is an error response and return it if exists
        if ($errorResponse !== null) {
            // Return error if user is not logged in
            return $errorResponse;
        }

        $customer = CustomerResource::getCurrentCustomer();

        $id = $request->getSafe('addressId', 'intval');


        $address = CustomerAddresses::query()->findOrFail($id);

        if ($address->customer_id != $customer->id) {
            return $this->sendError([
                'message' => __('You are not authorized to update this address', 'fluent-cart')
            ]);
        }

        $isUpdated = CustomerAddressResource::makePrimary(
            $customer->id,
            $request->getSafe('addressId', 'intval'),
            $request->getSafe('type', 'sanitize_text_field')
        );

        // Check for errors during the update process
        if (is_wp_error($isUpdated)) {
            return $this->sendError([
                'message' => $isUpdated->get_error_message()
            ]);
        }

        // Return the success response
        return $this->response->sendSuccess($isUpdated);
    }

    public function deleteCustomerProfileAddress(Request $request)
    {
        // Call the method and store the response
        $errorResponse = $this->checkUserLoggedIn();
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $id = $request->getSafe('addressId', 'intval');
        if (!$id) {
            return $this->sendError([
                'message' => __('Address ID is required', 'fluent-cart')
            ]);
        }

        $customer = CustomerResource::getCurrentCustomer();

        $address = CustomerAddresses::query()->findOrFail($id);

        if ($address->customer_id != $customer->id) {
            return $this->sendError([
                'message' => __('You are not authorized to update this address', 'fluent-cart')
            ]);
        }

        $isDeleted = CustomerAddressResource::delete($id);

        if (is_wp_error($isDeleted)) {
            return $this->sendError([
                'message' => $isDeleted->get_error_message()
            ]);
        }
        return $this->response->sendSuccess($isDeleted);
    }

    public function getDownloads(Request $request): \WP_REST_Response
    {
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 10);

        $errorResponse = $this->checkUserLoggedIn();

        if ($errorResponse !== null) {
            return $this->sendSuccess([
                'message'      => __('Success', 'fluent-cart'),
                'data'         => [],
                'total'        => 0,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => 1
            ]);
        }

        $customer = CustomerResource::getCurrentCustomer();

        $orderItems = OrderItem::query()
            ->with('variants')
            ->withWhereHas('order', function ($query) use ($customer) {
                $query->where('customer_id', $customer->id)
                    ->where(function (Builder $query) {
                        $query->whereIn('payment_status', Status::getOrderPaymentSuccessStatuses());
                    });
            })
            ->whereHas('product_downloads')
            ->get();

        $productIds = $orderItems->pluck('post_id')->unique()->values();
        $orders = $orderItems->pluck('order');

        // Extract all unique variation IDs from the customer's orders
        $variationIds = $orders->pluck('order_items')
            ->flatten()
            ->pluck('variants.id')
            ->filter()
            ->unique()
            ->values();

        $downloads = ProductDownload::query()->whereIn('post_id', $productIds)->get()->filter(function ($download) use ($variationIds) {
            $ids = $download->product_variation_id;
            return empty($ids) || array_intersect($variationIds->toArray(), $ids);
        });

        $orderIdMapByPostID = [];
        foreach ($orderItems as $orderItem) {
            if (!isset($orderIdMapByPostID[$orderItem->post_id])) {
                $orderIdMapByPostID[$orderItem->post_id] = [];
            }
            $orderIdMapByPostID[$orderItem->post_id][] = $orderItem->order_id;
        }

        $total = $downloads->count();
        $paginated = $downloads->forPage($page, $perPage)->values();


        $data = $paginated->map(function ($download) use ($orderIdMapByPostID) {
            return [
                'file_size'    => $download->file_size,
                'title'        => $download->title,
                'download_url' => Helper::generateDownloadFileLink(
                    $download,
                    Arr::get($orderIdMapByPostID, $download->post_id)
                ),
            ];
        })->values();

        return $this->sendSuccess([
            'message'   => __('Success', 'fluent-cart'),
            'downloads' => [
                'data'         => $data,
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int)ceil($total / $perPage),
            ]
        ]);
    }

    /*
     * Get upgradable paths for a given variation
    */
    public function getUpgradePaths(Request $request, $orderHash)
    {

        $currentCustomer = CustomerResource::getCurrentCustomer();
        if (!$currentCustomer) {
            return $this->sendError([
                'message' => __('You must be logged in to view upgrade paths.', 'fluent-cart')
            ]);
        }

        $order = Order::query()->where('uuid', $orderHash)
            ->where('customer_id', $currentCustomer->id)
            ->first();

        if (!$order) {
            return $this->sendError([
                'message' => __('Order not found or you do not have permission to view it.', 'fluent-cart')
            ]);
        }

        $variationId = $request->get('variation_id');
        if (!$variationId) {
            return [];
        }

        $upgradePaths = PlanUpgradeService::getUpgardePathsFromVariation($variationId, $orderHash);

        return $this->sendSuccess([
            'upgradePaths' => $upgradePaths
        ]);
    }

}
