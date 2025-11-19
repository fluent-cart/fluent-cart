<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\App;
use FluentCart\App\Models\Coupon;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class CouponRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules(): array
    {
        $startDate = $this->get("start_date");
        $endDate = $this->get("end_date");

        return [
            'title'                          => 'required|sanitizeText|maxLength:200',
            'code'                           => [
                'required',
                'string',
                'maxLength:50',
                function ($attribute, $value) {
                    $id = absint(App::request()->get('id'));

                    // Skip the check if updating and the code belongs to the same record
                    if ($id) {
                        $existing = Coupon::query()->where('code', $value)
                            ->where('id', '!=', $id)
                            ->first();
                    } else {
                        $existing = Coupon::query()->where('code', $value)->first();
                    }

                    if ($existing) {
                        return sprintf(__('This coupon code is already in use.', 'fluent-cart'));
                    }
                    return null;
                }

            ],
            'priority'                       => 'nullable|numeric|min:0',
            'type'                           => 'required|in:fixed,percentage,free_shipping,buy_x_get_y',
            'conditions'                     => 'nullable|array',
            'conditions.min_purchase_amount' => 'nullable|numeric|min:0',
            'conditions.max_discount_amount' => 'nullable|numeric|min:0',
            'conditions.apply_to_whole_cart' => 'nullable|sanitizeText',
            'conditions.apply_to_quantity'   => 'nullable|sanitizeText',
            'conditions.buy_products'        => 'nullable|array',
            'conditions.get_products'        => 'nullable|array',
            'conditions.max_per_customer'    => 'nullable|numeric',
            'conditions.excluded_categories' => 'nullable',
            'conditions.included_categories' => 'nullable',
            'conditions.excluded_products'   => 'nullable',
            'conditions.included_products'   => 'nullable',
            'conditions.email_restrictions'   => 'nullable',
            'conditions.max_uses'            => [
                'nullable',
                'numeric',
                function ($attribute, $value) {
                    if ($this->get("max_uses") < $this->get("max_per_customer")) {
                        return sprintf(__("Max uses must be greater than or equal to max per customer.", 'fluent-cart'));
                    }
                    return null;
                },
            ],
            'amount'                         => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value) {
                    if ($this->get("type") === 'percentage' && $value > 100) {
                        return sprintf(__("For percentage type, the amount should not be greater than 100.", 'fluent-cart'));
                    }
                    return null;
                },
            ],
            'status'                         => 'required|in:active,expired,disabled,scheduled',
            'notes'                          => 'nullable|sanitizeTextArea',
            'stackable'                      => 'required|sanitizeText|maxLength:50',
            'show_on_checkout'               => 'required|sanitizeText|maxLength:50',
            'start_date'                     => [
                'required_if:end_date,!=,null',
                'string',
                'nullable'
            ],
            'end_date'                       => [
                'nullable',
                'string',
                function ($attribute, $value) use ($startDate, $endDate) {
                    if ($value !== null) {
                        $startDateTime = strtotime(trim($startDate));
                        $endDateTime = strtotime(trim($endDate));

                        if ($endDateTime <= $startDateTime) {
                            return sprintf(esc_html__("The end date must be after the start date.", 'fluent-cart'));
                        }
                    }
                    return null;
                },
            ],
        ];
    }

    /**
     * @return array
     */
    public function messages(): array
    {
        return [
            'title.required'           => esc_html__('Title is required.', 'fluent-cart'),
            'code.required'            => esc_html__('Code is required.', 'fluent-cart'),
            'type.required'            => esc_html__('Type is required.', 'fluent-cart'),
            'amount.required'          => esc_html__('Amount is required.', 'fluent-cart'),
            'buy_quantity.required_if' => esc_html__('Buy quantity is required. ', 'fluent-cart'),
            'start_date.required_if'   => esc_html__('Start date is required. ', 'fluent-cart'),
            'end_date.required_if'     => esc_html__('End date is required. ', 'fluent-cart'),
            'end_date.date'            => esc_html__('The end date type should be date.', 'fluent-cart'),
        ];
    }

    /**
     * @return array
     */
    public function sanitize(): array
    {
        return [
            'title'                          => 'sanitize_text_field',
            'code'                           => 'sanitize_text_field',
            'priority'                       => 'intval',
            'type'                           => 'sanitize_text_field',
            'conditions'                     => function ($value) {

                $sanitizedData = [];
                $sanitizedData['min_purchase_amount'] = floatval(Arr::get($value, 'min_purchase_amount') ?? 0);
                $sanitizedData['max_discount_amount'] = floatval(Arr::get($value, 'max_discount_amount') ?? 0);
                $sanitizedData['max_purchase_amount'] = floatval(Arr::get($value, 'max_purchase_amount') ?? 0);
                $sanitizedData['apply_to_whole_cart'] = sanitize_text_field(Arr::get($value, 'apply_to_whole_cart') ?? 'no');
                $sanitizedData['apply_to_quantity'] = sanitize_text_field(Arr::get($value, 'apply_to_quantity') ?? 'no');
                $sanitizedData['max_uses'] = intval(Arr::get($value, 'max_uses') ?? 0);
                $sanitizedData['max_per_customer'] = intval(Arr::get($value, 'max_per_customer') ?? 0);
                $sanitizedData['excluded_categories'] = (is_array(Arr::get($value, 'excluded_categories')) ? Arr::get($value, 'excluded_categories') : []);
                $sanitizedData['included_categories'] = is_array(Arr::get($value, 'included_categories')) ? Arr::get($value, 'included_categories') : [];
                $sanitizedData['excluded_products'] = is_array(Arr::get($value, 'excluded_products')) ? Arr::get($value, 'excluded_products') : [];
                $sanitizedData['included_products'] = is_array(Arr::get($value, 'included_products')) ? Arr::get($value, 'included_products') : [];
                $sanitizedData['email_restrictions'] = sanitize_text_field(Arr::get($value, 'email_restrictions') ?? '');


                $arrayValues = ['excluded_categories', 'included_categories', 'excluded_products', 'included_products'];
                foreach ($arrayValues as $key) {
                    $sanitizedData[$key] = array_unique(array_map('sanitize_text_field', $sanitizedData[$key]));
                }

                return $sanitizedData;
            },
            'amount'                         => 'floatval',
            'conditions.apply_to_quantity'   => 'sanitize_text_field',
            'conditions.buy_quantity'        => 'intval',
            'conditions.get_quantity'        => 'intval',
            'conditions.max_uses'            => 'intval',
            'conditions.max_per_customer'    => 'intval',
            'status'                         => 'sanitize_text_field',
            'notes'                          => 'sanitize_text_field',
            'stackable'                      => 'sanitize_text_field',
            'show_on_checkout'               => 'sanitize_text_field',
            'start_date'                     => 'sanitize_text_field',
            'end_date'                       => 'sanitize_text_field',
            'metaValue'                      => function ($value) {
                return $value;
            }
        ];
    }
}
