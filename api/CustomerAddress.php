<?php

namespace FluentCart\Api;

use Exception;
use FluentCart\Api\Validator\CustomerAddressValidator;
use FluentCart\App\Helpers\CustomerHelper;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Validator\ValidationException;

class CustomerAddress
{
    /**
     * @var CustomerHelper $customerHelper An instance of the CustomerHelper service
     */
    private $customerHelper;

    public function __construct()
    {
        $this->customerHelper = (new CustomerHelper());
    }


    /**
     * @return null|ValidationException
     * 
     * @param array $args, which contain customers data like 
     *  first_name, last_name, email, city, state, postcode, country
     */
    public function validate($args)
    {
        try {
            (new CustomerAddressValidator())->validate($args);
        } catch (ValidationException $e) {
            throw $e;
        }
    }


    /**
     * @return array
     * 
     * create new customer address
     * 
     * @param string $id will be the address id
     * @param array $data will be the customer info
     */
	public function create($id, $data)
	{

        $address = Arr::get($data, 'address');

        $this->validate($address);

        foreach ($address as $key => $val) {
            $address[$key] = sanitize_text_field($val);
        }

        $hasPrimary = CustomerAddresses::query()->where('customer_id', $id)
            ->where('type', Arr::get($address, 'type'))
            ->where('is_primary', '1')
            ->count();

        if (!$hasPrimary) {
            $address['is_primary'] = '1';
        }

        $address['customer_id'] = intval($id);

        return CustomerAddresses::create($address);
	}


    /**
     * @return array
     * 
     * Update any existing customer address info
     * 
     * @param array $data , valid address details with fillable infos
     */
    public function update($data)
    {
        $this->validate(Arr::get($data, 'address'));

        $addressId = Arr::get($data, 'address.id', false);

        if (!$addressId) {
            throw new Exception(esc_html__('Please edit a valid address!', 'fluent-cart'));
        }

        $address = CustomerAddresses::query()->findOrFail($addressId);
        if (!$address) {
            throw new Exception(esc_html__('Address not found, please reload the page and try again!', 'fluent-cart'));
        }

        $data = Arr::get($data, 'address');
        foreach ($data as $key => $val) {
            $data[$key] = sanitize_text_field($val);
        }

        $address->update($data);
    }


    /**
     * @return Boolean
     * 
     * Delete existing customer or customers
     * 
     * @param string|Int $id , customer address id
     */
    public function delete($id)
    {
        if (!$id) {
            throw new Exception(esc_html__('Please use a valid address ID!', 'fluent-cart'));
        }
        return CustomerAddresses::query()->where('id', $id)->delete();
    }


    /**
     * @return array
     * 
     * Will return info of specific customer
     * 
     * @param string|Number $id get data by this customer id
     * @param array $type accepts a type of address like 'shipping', 'billing'
     */
    public function get($id, $type = 'billing')
    {
        $addresses = CustomerAddresses::search([ 'customer_id' => [ 'column' =>  'customer_id', 'operator' => '=', 'value' => $id ] ]);
        if ($type) {
            $addresses->search([ 'type' => [ 'column' =>  'type', 'operator' => '=', 'value' => $type ] ]);
        }
        return $addresses->orderBy('is_primary', 'DESC')->get()->toArray();
    }

    public function getAddress($id, $type = 'billing')
    {
        $all = $this->get($id, $type);

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


    /**
     * @return string message
     * 
     * @param array $data, accepts 'id' of address and 'type' of address 
     * 
     * possible type, 'billing', 'shipping'
     */
    public function makePrimary($customerId, $data)
    {
        $addressId = Arr::get($data, 'address.id');
        $type = Arr::get($data, 'address.type');

        CustomerAddresses::query()->where('customer_id', $customerId)
            ->where('type', $type)
            ->update(array('is_primary' => '0'));

        return CustomerAddresses::query()->where('id', $addressId)->update(array('is_primary' => '1'));
    }

}
