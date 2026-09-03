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

                    // Compare what will actually be STORED, not the raw input:
                    // sanitize() runs sanitize_text_field after validation, which
                    // trims — so a raw " CODE" passed this check unsanitized and
                    // then detonated on the fct_coupons UNIQUE index with a raw
                    // SQL error page (leading whitespace matters to MySQL VARCHAR
                    // comparison). Same ordering the MCP CouponTools already uses.
                    $code = sanitize_text_field((string) $value);

                    // Skip the check if updating and the code belongs to the same record
                    if ($id) {
                        $existing = Coupon::query()->where('code', $code)
                            ->where('id', '!=', $id)
                            ->first();
                    } else {
                        $existing = Coupon::query()->where('code', $code)->first();
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
            'conditions.max_purchase_amount' => [
                'nullable',
                'numeric',
                'min:0',
                function ($attribute, $value) {
                    // 0 / empty means "no max limit" (the form placeholder says
                    // so), therefore only cross-check when both sides are capped.
                    $min = floatval($this->get('conditions.min_purchase_amount'));
                    $max = floatval($value);

                    if ($max > 0 && $min > 0 && $min > $max) {
                        return sprintf(__('Max spend amount must be greater than or equal to min spend amount.', 'fluent-cart'));
                    }
                    return null;
                },
            ],
            'conditions.min_amount_basis'    => 'nullable|in:subtotal,total',
            'conditions.max_discount_amount' => 'nullable|numeric|min:0',
            'conditions.apply_to_whole_cart' => 'nullable|sanitizeText',
            'conditions.apply_to_quantity'   => 'nullable|sanitizeText',
            'conditions.buy_products'        => 'nullable|array',
            'conditions.get_products'        => 'nullable|array',
            // min:0 on both usage limits: the gates compare `count >= limit`
            // behind truthiness checks, so a persisted negative limit is truthy
            // and permanently reports "max uses exceeded" once the coupon (or
            // the customer) has a single use. 0 stays valid — it means
            // unlimited and is falsy-guarded past the gates.
            'conditions.max_per_customer'    => 'nullable|numeric|min:0',
            'conditions.excluded_categories' => 'nullable',
            'conditions.included_categories' => 'nullable',
            'conditions.excluded_products'   => 'nullable',
            'conditions.included_products'   => 'nullable',
            'conditions.email_restrictions'   => 'nullable',
            'conditions.is_recurring'   => 'nullable',
            'conditions.max_uses'            => [
                'nullable',
                'numeric',
                'min:0',
                function ($attribute, $value) {
                    // Both limits are optional and 0 means "unlimited", so only compare
                    // when the submission actually caps both.
                    $maxUses = intval($value);
                    $maxPerCustomer = intval($this->get("conditions.max_per_customer"));

                    if ($maxUses > 0 && $maxPerCustomer > 0 && $maxUses < $maxPerCustomer) {
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
            // in:yes,no — consumers disagree on how to read anything else (the
            // admin stacking gate checks === 'no', the storefront checks
            // === 'yes'), so a bogus value behaves differently per calculator.
            // status and type already constrain via in:; these were overlooked.
            'stackable'                      => 'required|in:yes,no',
            'show_on_checkout'               => 'required|in:yes,no',
            'start_date'                     => [
                // required_if only supports the equality form — the old
                // `required_if:end_date,!=,null` was never parsed and silently
                // no-oped. required_with is implicit and isPresent() treats
                // '' / null as absent, so this fires exactly when an end date
                // is supplied without a start date. No 'nullable' here: in this
                // validator nullable short-circuits implicit required_* rules
                // (verified). No bare 'string' rule either: the admin form
                // always sends the key, nulled when the schedule is empty, and
                // the string rule's presence check (Arr::has) treats that null
                // as present — so 'string' would reject every unscheduled
                // coupon. The closure enforces string-ness only on real values.
                'required_with:end_date',
                function ($attribute, $value) {
                    if (is_null($value) || $value === '') {
                        return null;
                    }
                    // is_string alone is not enough: a garbage string passed
                    // straight through to DateTime::anyTimeToGmt() in the
                    // controller, which threw a plugin_exception disclosing the
                    // absolute DateTime.php path. The value must actually parse.
                    if (!is_string($value) || strtotime(trim($value)) === false) {
                        return esc_html__('The start date must be a valid date string.', 'fluent-cart');
                    }
                    return null;
                }
            ],
            'end_date'                       => [
                'nullable',
                'string',
                function ($attribute, $value) use ($startDate) {
                    if ($value === null || $value === '') {
                        return null;
                    }

                    // strtotime('garbage') is false — the old comparison coerced
                    // it to 0, reporting unparseable end dates with a misleading
                    // end-after-start message (and letting them through entirely
                    // beside a pre-1970 start date's negative timestamp).
                    $endDateTime = is_string($value) ? strtotime(trim($value)) : false;
                    if ($endDateTime === false) {
                        return esc_html__('The end date must be a valid date string.', 'fluent-cart');
                    }

                    // Only compare when the start date itself parses — a bad
                    // start date is reported by its own rule, not this one.
                    $startDateTime = is_string($startDate) && $startDate !== ''
                        ? strtotime(trim($startDate))
                        : false;
                    if ($startDateTime !== false && $endDateTime <= $startDateTime) {
                        return sprintf(esc_html__("The end date must be after the start date.", 'fluent-cart'));
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
            'start_date.required_with' => esc_html__('Start date is required. ', 'fluent-cart'),
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
                // Only carry the basis when a valid value is supplied. When it is omitted the key is
                // left absent so create() can default it and update() can preserve the stored value —
                // never force 'subtotal' onto a legacy coupon whose client simply doesn't send the field.
                if (in_array(Arr::get($value, 'min_amount_basis'), ['subtotal', 'total'], true)) {
                    $sanitizedData['min_amount_basis'] = Arr::get($value, 'min_amount_basis');
                }
                $sanitizedData['apply_to_whole_cart'] = sanitize_text_field(Arr::get($value, 'apply_to_whole_cart') ?? 'no');
                $sanitizedData['apply_to_quantity'] = sanitize_text_field(Arr::get($value, 'apply_to_quantity') ?? 'no');
                $sanitizedData['max_uses'] = intval(Arr::get($value, 'max_uses') ?? 0);
                $sanitizedData['max_per_customer'] = intval(Arr::get($value, 'max_per_customer') ?? 0);
                $sanitizedData['excluded_categories'] = (is_array(Arr::get($value, 'excluded_categories')) ? Arr::get($value, 'excluded_categories') : []);
                $sanitizedData['included_categories'] = is_array(Arr::get($value, 'included_categories')) ? Arr::get($value, 'included_categories') : [];
                $sanitizedData['excluded_products'] = is_array(Arr::get($value, 'excluded_products')) ? Arr::get($value, 'excluded_products') : [];
                $sanitizedData['included_products'] = is_array(Arr::get($value, 'included_products')) ? Arr::get($value, 'included_products') : [];
                $sanitizedData['email_restrictions'] = sanitize_text_field(Arr::get($value, 'email_restrictions') ?? '');
                $sanitizedData['is_recurring'] = Arr::get($value, 'is_recurring') === 'yes' ? 'yes' : 'no';


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
