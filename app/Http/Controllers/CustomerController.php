<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\CustomerAddressResource;
use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Helpers\CustomerHelper;
use FluentCart\App\Http\Requests\AttachUserRequest;
use FluentCart\App\Http\Requests\CustomerAddressRequest;
use FluentCart\App\Http\Requests\CustomerRequest;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\User;
use FluentCart\App\Services\Filter\CustomerFilter;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\Framework\Database\Orm\Collection;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class CustomerController extends Controller
{
    public function index(Request $request): \WP_REST_Response
    {
        return $this->sendSuccess(
            [
                'customers' => CustomerFilter::fromRequest($request)->paginate()
            ]
        );
    }

    public function store(CustomerRequest $request)
    {
        $data = $request->getSafe($request->sanitize());
        $isCreated = CustomerResource::create($data);

        if (is_wp_error($isCreated)) {
            return $isCreated;
        }
        return $this->response->sendSuccess($isCreated);
    }

    public function update(CustomerRequest $request, $customerId)
    {
        $data = $request->getSafe($request->sanitize());
        $isUpdated = CustomerResource::update($data, $customerId);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function find(Request $request, $customerId)
    {

        $with = $request->get('with', []);

        $customer = Customer::with($with)->find($customerId);

        if (empty($customer)) {
            return $this->entityNotFoundError(
                __('Customer not found', 'fluent-cart'),
                __('Back to Customer List', 'fluent-cart'),
                '/customers'
            );
        }

        $selectedLabels = Collection::make($customer['labels'])->pluck('label_id');
        if ($request->get('params.customer_only') === 'yes') {
            return $this->sendSuccess(['customer' => $customer]);
        }

        $customer['selected_labels'] = $selectedLabels;

        $customer = apply_filters('fluent_cart/customer/view', $customer, $request->all());
        return $this->sendSuccess(['customer' => $customer]);
    }

    public function findOrder(Request $request, $customerId)
    {

        return ['data' => CustomerResource::findOrder($customerId)];
    }

    public function updateAdditionalInfo(Request $request, $customerId)
    {
        $isUpdated = CustomerResource::updateAdditionalInfo($request->all(), $customerId);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function getAddress(Request $request, $customerId)
    {
        return [
            'addresses' => CustomerAddressResource::get([
                'customer_id' => $customerId,
                'type'        => $request->type
            ])
        ];
    }

    public function createAddress(CustomerAddressRequest $request, $customerId)
    {

        $data = $request->getSafe($request->sanitize());
        $isCreated = CustomerAddressResource::create($data, ['id' => $customerId, 'order_id' => intval(Arr::get($request->all(), 'order_id', null))]);

        if (is_wp_error($isCreated)) {
            return $isCreated;
        }
        return $this->response->sendSuccess($isCreated);
    }

    public function updateAddress(CustomerAddressRequest $request)
    {

        $data = $request->getSafe($request->sanitize());
        $id = Arr::get($request->all(), 'id');
        $isUpdated = CustomerAddressResource::update($data, $id, ['order_id' => intval(Arr::get($request->all(), 'order_id', null))]);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function removeAddress(Request $request)
    {

        $id = Arr::get($request->address, 'id', false);
        $isDeleted = CustomerAddressResource::delete($id);

        if (is_wp_error($isDeleted)) {
            return $isDeleted;
        }
        return $this->response->sendSuccess($isDeleted);
    }

    public function setAddressPrimary(Request $request, $customerId)
    {
        $isUpdated = CustomerAddressResource::makePrimary(
            $customerId,
            $request->getSafe('addressId', 'intval'),
            $request->getSafe('type', 'sanitize_text_field')
        );

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function getCustomerOrders(Request $request, $customerId): array
    {
        $data = $request->all();
        $orderFilter = OrderFilter::fromRequest($request);
        $orderFilter->query = $orderFilter->query->where('customer_id', $customerId);
        return [
            'orders' => $orderFilter->paginate()
        ];
    }

    public function handleBulkActions(Request $request, CustomerHelper $customerHelper)
    {
        $isUpdated = CustomerResource::manageCustomer($request->all());

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function getStats($customerId): \WP_REST_Response
    {
        $customer = CustomerResource::find($customerId);
        return $this->sendSuccess([
            'widgets' => apply_filters('fluent_cart/widgets/single_customer', [], $customer)
        ]);
    }


    public function getAttachableUser(): \WP_REST_Response
    {
        return $this->sendSuccess([
            'users' => User::query()->select('ID', 'display_name', 'user_email')->whereDoesntHave('customer')->get()
        ]);
    }

    public function setAttachableUser(AttachUserRequest $request, $customerId): \WP_REST_Response
    {

        $customer = Customer::query()->with('wpUser')->find($customerId);

        if (empty($customer)) {

            return $this->sendError([
                'message' => __('Customer not found.', 'fluent-cart')
            ]);
        }

        if (!empty($customer->wpUser)) {
            return $this->sendError([
                'message' => __('Can not attach user', 'fluent-cart')
            ]);
        }

        $data = $request->getSafe($request->sanitize());
        $userId = Arr::get($data, 'user_id');


        $customer->user_id = $userId;
        $attached = $customer->save();

        if ($attached) {
            return $this->sendSuccess([
                'message' => __('User attached successfully', 'fluent-cart')
            ]);
        } else {
            return $this->sendError([
                'message' => __('Can not attach user', 'fluent-cart')
            ]);
        }


    }

    public function detachCustomer(Request $request, $customerId): \WP_REST_Response
    {

        $customer = Customer::query()->find($customerId);

        if (empty($customer)) {
            return $this->sendError([
                'message' => __('Customer not found.', 'fluent-cart')
            ]);
        }


        $customer->user_id = null;
        $detached = $customer->save();

        if ($detached) {
            return $this->sendSuccess([
                'message' => __('User detached successfully', 'fluent-cart')
            ]);
        } else {
            return $this->sendError([
                'message' => __('Can not detach user', 'fluent-cart')
            ]);
        }
    }
}
