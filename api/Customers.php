<?php

namespace FluentCart\Api;

use FluentCart\Api\Validator\CustomerValidator;
use FluentCart\App\Helpers\CustomerHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Validator\ValidationException;

class Customers
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
     * @return Boolean
     * 
     * Check email is already available or not
     * 
     * @param Email $email, Customer email address
     */
    public function isExist($email)
    {
        return Customer::query()->where('email', $email)->exists();
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
            (new CustomerValidator())->validate($args);
        } catch (ValidationException $e) {
            throw $e;
        }
    }


    /**
     * @return array
     * 
     * create new customer
     * 
     * @param array $args will be the customer info
     */
	public function create($args)
	{
        try {
            $this->customerHelper->hasPermission();

            $this->validate($args);
            
            $this->customerHelper->createCustomer($args)->toArray();

        } catch (ValidationException $e) {
            throw $e;
        }
        
	}


    /**
     * @return bool
     * 
     * Update any existing customer basic info
     * 
     * @param array $id , valid customer id
     * @param array $data , customer data to update
     */
    public function update($id, $data)
    {
        return Customer::query()->findOrFail($id)
        ->update(
            $this->customerHelper->sanitizeCustomer($data)
        );
    }


    /**
     * @return array
     * 
     * Delete existing customer or customers
     * 
     * @param array $ids , customer id or array of id
     */
    public function delete($ids)
    {
        $customers = Customer::with(['orders'])->whereIn('id', $ids)->get();

        foreach ($customers as $customer) {
            $customer->orders()->delete();
            $customer->delete();
        }

        return [
            'message' => __('Selected Customers has been deleted permanently', 'fluent-cart'),
        ];
    }


    /**
     * @return array
     *
     * Will return info of specific customer
     * 
     * @param string|Number $id, get data by this customer id
     * @param array $with, accepts array of 'orders', 'shipping_address', 'billing_address'
     */
    public function get($id, $with = [])
    {
        return Customer::with($with)->findOrFail($id)->toArray();
    }


    /**
     * @return array
     * 
     * will return all customers with pagination
     * 
     * @param array $args, arguments of pagination info
     */
    public function getAll($args)
	{
        $customers = Customer::query()->when(Arr::get($args["params"], 'search'), function ($query) use ($args) {
            return $query->search(Arr::get($args["params"], 'search', ''));
          })
          ->applyCustomFilters(Arr::get($args["params"], 'filters', []))
          ->orderBy(
            sanitize_sql_orderby(Arr::get($args["params"], 'order_by', 'id')), 
            sanitize_sql_orderby(Arr::get($args["params"], 'order_type', 'DESC')))
          ->paginate(Arr::get($args["params"], 'per_page', 15), ['*'], 'page', Arr::get($args["params"], 'page'));

        return $customers;
	}


    /**
     * @return string
     * 
     * Delete existing customer or customers
     * 
     * @param array $arg , 
     *       'new_status': active|inactive, 
     *       'customer_ids': [], 
     *       'action': change_customer_status|delete_customers
     */
    public function updateStatus($arg)
    {
        $newStatus = sanitize_text_field(Arr::get($arg, 'new_status', ''));
        if (!$newStatus) {
            throw new \Exception(esc_html__('Please select status', 'fluent-cart'));
        }

        $validStatuses = Status::getEditableCustomerStatuses();
        if (!isset($validStatuses[$newStatus])) {
            throw new \Exception(esc_html__('Provided customer status is not valid', 'fluent-cart'));
        }

        $customers = Customer::with(['orders'])->whereIn('id', Arr::get($arg, 'customer_ids'))->get();
        foreach ($customers as $customer) {
            $customer->updateCustomerStatus($newStatus);
        }

        return [
            'message' => __('Customer Status has been changed', 'fluent-cart')
        ];
    }

}
