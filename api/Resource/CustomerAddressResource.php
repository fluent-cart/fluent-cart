<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\App;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class CustomerAddressResource extends BaseResourceApi
{

    public static function getQuery(): Builder
    {
        return CustomerAddresses::query();
    }

    /**
     * Get customer addresses with specified parameters.
     *
     * @param array $params Array containing the necessary parameters.
     *   [
     *       'customer_id' => (int) Required. The ID of the customer.
     *       'type' => (string) Optional. The type of address ('billing' by default).
     *   ]
     *
     */
    public static function get(array $params = [])
    {
        $id = Arr::get($params, 'customer_id');

        $type = Arr::get($params, 'type', 'billing');

        $addresses = static::getQuery()->search(['customer_id' => ['column' => 'customer_id', 'value' => $id]]);

        if ($type) {
            $addresses->search(['type' => ['column' => 'type', 'value' => $type]]);
        }
        return $addresses->orderBy('is_primary', 'DESC')->get()->toArray();
    }

    /**
     * Find customer address based on the given ID and parameters.
     *
     * @param int $id Required. The ID of the customer.
     * @param array $params Optional. Array containing the necessary parameters.
     *   [
     *       'type' => (string) Optional. The type of address ('billing' by default).
     *   ]
     *
     */
    public static function find($id, $params = [])
    {
        $type = Arr::get($params, 'type', 'billing');
        $params = [
            "customer_id" => $id,
            "type" => $type,
        ];
        $all = CustomerAddressResource::get($params);

        if (empty($all)) {
            return [];
        }

        $firstItem = Arr::first($all);
        return Arr::only($firstItem, [
            'address_1',
            'address_2',
            'city',
            'state',
            'postcode',
            'country'
        ]);
    }

    /*public static function find($id, $params = [])
    {
        return static::getQuery()->find($id);
    }*/

    /**
     * Create customer address with the given data.
     *
     * @param array $data Required. Array containing the necessary parameters
     *        [
     *              'email'        => (string) Optional. The email of the address.
     *              'name'         => (string) Required. The name of the address.
     *              'phone'        => (string) Required. The phone of the address.
     *              'address_1'    => (string) Required. The primary address line.
     *              'address_2'    => (string) Optional. The secondary address line.
     *              'city'         => (string) Required. The city of the address.
     *              'country'      => (string) Required. The country of the address.
     *              'postcode'     => (string) Required. The postal code of the address.
     *              'state'        => (string) Required. The state of the address.
     *              'type'         => (string) Required. (e.g., 'billing', 'shipping').
     *        ]
     * @param array $params Required. Additional parameters for creating an address.
     *        [
     *             'id' => (int) Required. The ID of the customer.
     *        ]
     *
     */
    public static function create($data, $params = [])
    {
        // Extract the customer ID from the provided parameters
        $id = Arr::get($params, 'id');

        // Retrieve the 'type' of address from the provided data array
        $addressType = Arr::get($data, 'type');

        // Check if the customer already has a primary address of the given type
        $hasPrimary = static::getQuery()
            ->where('customer_id', $id) // Match the customer ID
            ->where('type', Arr::get($data, 'type')) // Match the address type from the data
            ->where('is_primary', '1') // Look for addresses marked as primary
            ->count(); // Get the count of matching primary addresses

        // If no primary address exists, set 'is_primary' to '1'
        if (!$hasPrimary) {
            $data['is_primary'] = '1';
        }

        // Assign the customer ID to the data array
        $data['customer_id'] = $id;

        // Create a new address record with the given data
        $isCreated = static::getQuery()->create($data);

        // If the address was successfully created
        if ($isCreated) {
            // Check if an 'order_id' is provided and the address is marked as primary
            if (!empty($orderId = Arr::get($params, 'order_id', null)) && Arr::get($data, 'is_primary', null) == 1) {
                // Include 'order_id' in the data for the order address
                $data['order_id'] = $orderId;
                // Check if the order already has an address of the given type
                $alreadyOrderHasAddress = OrderAddressResource::find($orderId, ['type' => Arr::get($data, 'type', null)]);

                if ($alreadyOrderHasAddress) {
                    // If an address already exists for the order, update it with the new data
                    OrderAddressResource::update($data, $orderId);
                } else {
                    // If no address exists for the order, create a new one
                    OrderAddressResource::create($data);
                }
            }

            if ($addressType === 'billing') {
                return static::makeSuccessResponse(
                    $isCreated,
                    __('Billing address created successfully!', 'fluent-cart')
                );
            }
            return static::makeSuccessResponse(
                $isCreated,
                __('Shipping address created successfully!', 'fluent-cart')
            );
        }

        if ($addressType === 'billing') {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Failed creating billing address', 'fluent-cart')]
            ]);
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed creating shipping address', 'fluent-cart')]
        ]);
    }

    /**
     * Update the customer address with the given data.
     *
     * @param array $data Required. Array containing the necessary parameters
     *        [
     *              'Email'        => (string) Optional. The email of the address.
     *              'Name'         => (string) Required. The name of the address.
     *              'Phone'        => (string) Required. The phone of the address.
     *              'Address_1'    => (string) Required. The primary address line.
     *              'Address_2'    => (string) Optional. The secondary address line.
     *              'City'         => (string) Required. The city of the address.
     *              'Country'      => (string) Required. The country of the address.
     *              'Postcode'     => (string) Required. The postal code of the address.
     *              'State'        => (string) Required. The state of the address.
     *              'Type'         => (string) Required. (E.g., 'billing', 'shipping').
     *        ]
     * @param int $id Required. The ID of the customer address to be updated.
     *
     */
    public static function update($data, $id, $params = [])
    {
        if (!$id) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Please edit a valid address!', 'fluent-cart')]
            ]);
        }

        $address = static::getQuery()->find($id);

        // Retrieve the 'type' of address from the provided data array
        $addressType = Arr::get($address, 'type');


        if (!$address) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Address not found, please reload the page and try again!', 'fluent-cart')]
            ]);
        }

        $address->update($data);
        $address->refresh();

        if ($address) {
            if (!empty($orderId = Arr::get($params, 'order_id', null))) {
                OrderAddressResource::update($data, $orderId);
            }

            if ($addressType === 'billing') {
                return static::makeSuccessResponse(
                    $address,
                    __('Billing address created successfully!', 'fluent-cart')
                );
            }

            return static::makeSuccessResponse(
                $address,
                __('Shipping address created successfully!', 'fluent-cart')
            );
        }

        if ($addressType === 'billing') {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Failed creating billing address', 'fluent-cart')]
            ]);
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed creating shipping address', 'fluent-cart')]
        ]);
    }

    /**
     * Delete an address based on the given ID and parameters.
     *
     * @param int $id Required. The ID of the customer address.
     * @param array $params Optional. Additional parameters for address deletion.
     *
     */
    public static function delete($id, $params = [])
    {
        // Check if a valid ID is provided
        if (!$id) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Please use a valid address ID!', 'fluent-cart')]
            ]);
        }

        // Retrieve the customer address using the ID
        $customerAddress = static::getQuery()->find($id);

        if ($customerAddress) {
            // Check if the address is marked as primary
            if ($customerAddress->is_primary) {
                return static::makeErrorResponse([
                    ['code' => 403, 'message' => __('Primary address cannot be deleted!', 'fluent-cart')]
                ]);
            }
            // Get the count of addresses for this customer
            $addressCount = static::getQuery()->where('customer_id', $customerAddress->customer_id)->count();

            // Check if there's only one address, do not allow deletion in that case
            if ($addressCount <= 1) {
                return static::makeErrorResponse([
                    ['code' => 403, 'message' => __('At least one address must remain. Address deletion failed!', 'fluent-cart')]
                ]);
            }

            // If the address is not primary and there are multiple addresses, proceed with deletion
            if ($customerAddress->delete()) {
                return static::makeSuccessResponse('', __('Address successfully deleted.', 'fluent-cart'));
            }

            // Return an error if the address is not found in the database
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Address deletion failed!', 'fluent-cart')]
            ]);
        }

        return static::makeErrorResponse([
            ['code' => 404, 'message' => __('Address not found in database, failed to remove.', 'fluent-cart')]
        ]);
    }

    /**
     * Make primary address for the specified customer.
     *
     * @param int $customerId Required. The ID of the customer.
     * @param array $params Optional. Additional parameters for making an address primary.
     *     [
     *       'address' => [
     *           'id'   => (int) Required. The ID of the address to be set as primary.
     *           'type' => (string) Required. The type of the address (e.g., 'billing', 'shipping').
     *       ],
     *    ]
     */
    public static function makePrimary($customerId, $addressId, $type)
    {
        static::getQuery()->where('customer_id', $customerId)
            ->where('type', $type)
            ->update(array('is_primary' => '0'));

        $isUpdated = static::getQuery()->where('id', $addressId)->update(array('is_primary' => '1'));

        if ($isUpdated) {
            return static::makeSuccessResponse('', __('Address successfully set as the primary', 'fluent-cart'));
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Address set as primary failed.', 'fluent-cart')]
        ]);
    }
}