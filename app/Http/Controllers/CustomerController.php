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
use FluentCart\App\Services\Permission\PermissionManager;
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

        $with = $this->resolveEagerLoads($request->get('with', []));

        $customer = Customer::with($with)->find($customerId);

        if (empty($customer)) {
            return $this->entityNotFoundError(
                __('Customer not found', 'fluent-cart'),
                __('Back to Customer List', 'fluent-cart'),
                '/customers'
            );
        }

        // Read the labels relation ONLY when the allow-list actually loaded it.
        // `$customer['labels']` on its own lazy-loads the relation and caches it
        // into $customer->relations, which relationsToArray() then serializes —
        // so the plain array access put the whole labels subtree in the response
        // for a caller who neither asked for it nor holds labels/view, and the
        // gate below it was decorative.
        $labels = $customer->relationLoaded('labels') ? $customer->getRelation('labels') : [];
        $selectedLabels = Collection::make($labels)->pluck('label_id');

        if ($request->get('params.customer_only') === 'yes') {
            return $this->sendSuccess(['customer' => $customer]);
        }

        $customer['selected_labels'] = $selectedLabels;

        $customer = apply_filters('fluent_cart/customer/view', $customer, $request->all());
        return $this->sendSuccess(['customer' => $customer]);
    }

    /**
     * What the `with` parameter on `GET customers/{id}` may eager-load.
     *
     * ## The entry form
     *
     * Every entry is a LITERAL request key mapped to a CALLABLE. The key is never
     * decomposed, prefix-matched or suffix-stripped, so what the client sends is
     * either a key in this map or it is dropped. That is what keeps every ORM
     * `with` shape out on its own: the dotted `orders.customer.wpUser`, the
     * column-select `wpUser:ID,user_pass` and the nested array
     * `with[orders][]=customer.wpUser` are all non-keys here.
     *
     * The callback owns the whole path AND its own permission bar, and returns
     * the relation paths to eager-load — an empty array when it refuses. The
     * caller merges what comes back; a refusing callback contributes nothing.
     *
     * ## Two tiers of key
     *
     * A SCREEN key names a calling screen and loads exactly the subtree that
     * screen renders, so the screen can be re-scoped without widening the payload
     * for anybody else. A PUBLIC key is a plain relation name an external
     * consumer of a customer endpoint can reasonably ask for; each one carries
     * the same gate its screen-key counterpart carries.
     *
     * ## What stays off the map
     *
     * `wpUser` is absent and must stay absent at EVERY nesting depth: it is a
     * BelongsTo onto the WordPress `users` table, so loading it hands the caller
     * the password hash (`user_pass`) and the password-reset token
     * (`user_activation_key`). No callback below names it, and no dotted path can
     * reach it because dotted paths are not keys.
     *
     * Kept local to this controller rather than folded into
     * `Services/Filter/BaseFilter::allowedWiths()`: that map adopts a Builder
     * returned by each callback, while this endpoint eager-loads onto a model
     * lookup, and the two maps share no entry.
     *
     * @return array<string, callable>
     */
    private function allowedWiths(): array
    {
        return [
            'admin_customer_detail'    => [$this, 'adminCustomerDetail'],

            // The public entry points. Each is the plain, unnested version of a
            // relation the screen key above loads a shaped subtree of, and each
            // repeats that key's gate. Same callbacks would be misleading here —
            // the screen key returns four relations at once, these return one.
            'shipping_address'         => [$this, 'publicShippingAddress'],
            'billing_address'          => [$this, 'publicBillingAddress'],
            'primary_shipping_address' => [$this, 'publicPrimaryShippingAddress'],
            'primary_billing_address'  => [$this, 'publicPrimaryBillingAddress'],
            'labels'                   => [$this, 'publicLabels'],
            'subscriptions'            => [$this, 'publicSubscriptions'],
        ];
    }

    /**
     * `Modules/Customers/SingleCustomer.vue` fetch() — the customer detail screen,
     * and the only caller of this endpoint in the admin app.
     *
     * It renders the two address blocks, the label chips and the subscriptions
     * table, so it gets exactly those four relations and no `orders`: the same
     * screen pulls its order table from `GET customers/{id}/orders`, which
     * paginates.
     *
     * The gates match what each relation reaches, not what the screen wants:
     * addresses are customer-owned rows and carry nothing beyond the customer
     * record the route already granted, while labels and subscriptions are
     * separate resources with their own permission.
     *
     * @return array relation paths
     */
    private function adminCustomerDetail(): array
    {
        if (!PermissionManager::hasPermission('customers/view')) {
            return [];
        }

        $relations = ['shipping_address', 'billing_address'];

        if (PermissionManager::hasPermission('labels/view')) {
            $relations[] = 'labels';
        }

        if (PermissionManager::hasPermission('subscriptions/view')) {
            $relations[] = 'subscriptions';
        }

        return $relations;
    }

    /**
     * The customer's shipping addresses. A customer-owned row: the route's own
     * `customers/view` is the whole bar, restated here so the entry still refuses
     * if this method is ever reached from somewhere the route did not guard.
     *
     * @return array relation paths
     */
    private function publicShippingAddress(): array
    {
        if (!PermissionManager::hasPermission('customers/view')) {
            return [];
        }

        return ['shipping_address'];
    }

    /**
     * The customer's billing addresses. Same bar as the shipping addresses.
     *
     * @return array relation paths
     */
    private function publicBillingAddress(): array
    {
        if (!PermissionManager::hasPermission('customers/view')) {
            return [];
        }

        return ['billing_address'];
    }

    /**
     * The single address flagged primary for shipping. Same bar again — it is a
     * narrowed `shipping_address`, not a different resource.
     *
     * @return array relation paths
     */
    private function publicPrimaryShippingAddress(): array
    {
        if (!PermissionManager::hasPermission('customers/view')) {
            return [];
        }

        return ['primary_shipping_address'];
    }

    /**
     * The single address flagged primary for billing.
     *
     * @return array relation paths
     */
    private function publicPrimaryBillingAddress(): array
    {
        if (!PermissionManager::hasPermission('customers/view')) {
            return [];
        }

        return ['primary_billing_address'];
    }

    /**
     * The labels attached to this customer. A separate resource with its own
     * permission, so `customers/view` alone is not enough.
     *
     * @return array relation paths
     */
    private function publicLabels(): array
    {
        if (!PermissionManager::hasPermission('labels/view')) {
            return [];
        }

        return ['labels'];
    }

    /**
     * The customer's subscriptions. `subscriptions/view` is the bar.
     *
     * UNBOUNDED, and knowingly so. This is a to-many with no LIMIT, so a
     * customer with a long history costs a proportional number of rows,
     * hydrated models and serialized output on one request. `orders` used to sit
     * beside it and was removed for exactly that reason — but `orders` had a
     * paginated endpoint to redirect to (`GET customers/{id}/orders`) and no
     * production caller, while this one has neither.
     *
     * SingleCustomer.vue renders `customer.subscriptions` as a complete list
     * with a count, so capping it here would silently truncate a list the UI
     * presents as whole — worse than the unbounded read.
     *
     * The fix is a paginated subscriptions endpoint, after which this key goes
     * the same way `orders` did. Until then the exposure is real and this
     * comment is the record of it.
     *
     * @return array relation paths
     */
    private function publicSubscriptions(): array
    {
        if (!PermissionManager::hasPermission('subscriptions/view')) {
            return [];
        }

        return ['subscriptions'];
    }

    /**
     * Reduce a client-supplied `with` payload to the relation paths this endpoint
     * is allowed to eager-load.
     *
     * Anything that is not a literal key of allowedWiths() is dropped SILENTLY.
     * An unknown relation otherwise reaches Builder::getRelation(), which turns a
     * BadMethodCallException into a RelationNotFoundException — a 500 — so a
     * stale admin build would hard-fail where it should degrade.
     *
     * Only STRING request entries are considered. Dropping the rest is what kills
     * the nested-array shape `with[orders][]=customer.wpUser`: its value is an
     * array, and its key is never read.
     *
     * @param mixed $with raw request value
     * @return array relation names safe to pass to Customer::with()
     */
    private function resolveEagerLoads($with): array
    {
        $map = $this->allowedWiths();

        $resolved = [];

        foreach (Arr::wrap($with) as $requestKey) {
            if (!is_string($requestKey) || !array_key_exists($requestKey, $map)) {
                continue;
            }

            $entry = $map[$requestKey];

            if (!is_callable($entry)) {
                continue;
            }

            foreach ((array) $entry() as $relation) {
                if (is_string($relation) && $relation !== '') {
                    $resolved[$relation] = true;
                }
            }
        }

        return array_keys($resolved);
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
        $data = CustomerAddressResource::normalizeBusinessFields($data);
        $isCreated = CustomerAddressResource::create($data, ['id' => $customerId, 'order_id' => intval(Arr::get($request->all(), 'order_id', null))]);

        if (is_wp_error($isCreated)) {
            return $isCreated;
        }
        return $this->response->sendSuccess($isCreated);
    }

    public function updateAddress(CustomerAddressRequest $request)
    {

        $data = $request->getSafe($request->sanitize());
        $data = CustomerAddressResource::normalizeBusinessFields($data);
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

    public function recalculateLtv(Request $request, $customerId): \WP_REST_Response
    {
        $customer = Customer::query()->find($customerId);

        if (empty($customer)) {
            return $this->sendError([
                'message' => __('Customer not found.', 'fluent-cart')
            ]);
        }

        $customer->recountStat();

        return $this->sendSuccess([
            'message'  => __('Lifetime value recalculated successfully', 'fluent-cart'),
            'customer' => $customer
        ]);
    }

}
