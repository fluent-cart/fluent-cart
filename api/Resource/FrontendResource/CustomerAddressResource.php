<?php

namespace FluentCart\Api\Resource\FrontendResource;

use FluentCart\Api\Resource\BaseResourceApi;
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
     *        'status' => (enum) active|.
     *       'type' => (string) Optional. The type of address ('billing' by default).
     *   ]
     *
     */
    public static function get(array $params = [])
    {
        $id = Arr::get($params, 'customer_id');

        $type = Arr::get($params, 'type', 'billing');

        $addresses = static::getQuery()
            ->addAppends(['formatted_address'])
//            ->when($type, function ($builder, $type) {
//                return $builder->search(['type' => ['column' => 'type', 'value' => $type]]);
//            })
            ->search($params);
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
        $address = static::getQuery()->find($id);
        return [
            'address' => $address
        ];
    }

    /**
     * Find customer address based on the customer ID and parameters.
     *
     * @param int $id Required. The ID of the customer.
     * @param array $params Optional. Array containing the necessary parameters.
     *   [
     *       'type' => (string) Optional. The type of address ('billing' by default).
     *   ]
     *
     */
    private static function findByCustomer($id, $params = [])
    {
        $type = Arr::get($params, 'type', 'billing');

        return static::getQuery()->where('customer_id', $id)
            ->where('type', $type)
            ->get();
    }

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
        $id = Arr::get($params, 'id');

        $totalAddress = self::findByCustomer($id, $data)->count();
        $hasPrimary = self::findByCustomer($id, $data)->where('is_primary', '1')->count();

        if (!$hasPrimary) {
            $data['is_primary'] = '1';
        }
        $data['customer_id'] = $id;

        // set others data as meta
        $otherData = Arr::only($data, ['company_name', 'first_name', 'last_name', 'phone']);
        Arr::set($data, 'meta.other_data', $otherData);

        $isCreated = static::getQuery()->create($data);

        if ($isCreated) {
            return static::makeSuccessResponse(
                [
                    'is_created' => $isCreated,
                    'total_address_count' => $totalAddress,

                ],
                __('Customer address created successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Customer address creation failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Update customer address with the given data.
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

        if (!$address) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Address not found, please reload the page and try again!', 'fluent-cart')]
            ]);
        }

        $isUpdated = $address->update($data);

        if ($isUpdated) {
            static::makeSuccessResponse($isUpdated, __('Customer address updated successfully!', 'fluent-cart'));
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Customer address update failed.', 'fluent-cart')]
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
        if (!$id) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Please use a valid address ID!', 'fluent-cart')]
            ]);
        }

        $customerAddress = static::getQuery()->find($id);

        if ($customerAddress) {
            if ($customerAddress->delete()) {
                return static::makeSuccessResponse('', __('Address successfully deleted.', 'fluent-cart'));
            }

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
    public static function makePrimary($customerId, $params = [])
    {
        $addressId = Arr::get($params, 'address.id');
        $type = Arr::get($params, 'address.type');

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