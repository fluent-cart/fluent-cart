<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;

class EditorShortCodeHelper
{
    public static function getGeneralShortCodes(): array
    {
        return [
            'title'      => __('General', 'fluent-cart'),
            'key'        => 'wp',
            'shortcodes' => [
                '{{wp.admin_email}}'    => __('Admin Email', 'fluent-cart'),
                '{{wp.site_url}}'       => __('Site URL', 'fluent-cart'),
                '{{wp.site_title}}'     => __('Site Title', 'fluent-cart'),
                '{{user.ID}}'           => __('User ID', 'fluent-cart'),
                '{{user.display_name}}' => __('User Display Name', 'fluent-cart'),
                '{{user.first_name}}'   => __('User First Name', 'fluent-cart'),
                '{{user.last_name}}'    => __('User Last Name', 'fluent-cart'),
                '{{user.user_email}}'   => __('User Email', 'fluent-cart'),
                '{{user.user_login}}'   => __('User Username', 'fluent-cart')
            ],
        ];
    }

    public static function getSettingsShortCodes(): array
    {
        return [
            'title'      => __('Settings', 'fluent-cart'),
            'key'        => 'settings',
            'shortcodes' => [
                '{{settings.store_name}}'     => __('Store Name', 'fluent-cart'),
                '{{settings.store_logo}}'     => __('Store Logo', 'fluent-cart'),
                '{{settings.store_address}}'  => __('Store Address Line 1', 'fluent-cart'),
                '{{settings.store_address2}}' => __('Store Address Line 2', 'fluent-cart'),
                '{{settings.store_country}}'  => __('Store Country', 'fluent-cart'),
                '{{settings.store_state}}'    => __('Store State', 'fluent-cart'),
                '{{settings.store_city}}'     => __('Store City', 'fluent-cart'),
                '{{settings.store_postcode}}' => __('Store Postcode', 'fluent-cart'),
            ],
            'group'      => 'settings'
        ];
    }


    static function collectShortcodeFromNestedTextFields($array, $prefix = '')
    {
        $text_fields = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (isset($value['type']) && $value['type'] === 'text' && isset($value['label'])) {
                    $label = isset($value['label']) && $value['label']
                        ? $value['label']
                        : ucwords(str_replace('_', ' ', $key));

                    $text_fields['{{' . $prefix . '.' . $key . '}}'] = $label;
                } elseif (isset($value['schema'])) {
                    $text_fields = array_merge($text_fields, static::collectShortcodeFromNestedTextFields($value['schema'], $prefix));
                }
            }
        }

        return $text_fields;
    }

    /**
     * Make shortCodes from array
     * @array  array [ key => [ ... arguments] ]
     * @parentKey string will add to the key of array label
     * @type $type string 'keyPair', will only return a key and label (string) array
     * @return array array of shortCodes Exm: [ {{my.shortcode}} => 'My Shortcode' ]
     */
    public static function makeShortCodes(array $array, $parentKey = '', $type = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $parentKey ? $parentKey . '.' . $key : $key;
            if (isset($value['label'])) {
                $title = $value['label'] . ' (' . $parentKey . ')';
                $code = '{{' . $newKey . '}}';
                if ($type === 'keyPair') {
                    $result[$code] = $title;
                } else {
                    $value['attributes'] = [
                        'code' => '{{' . $newKey . '}}',
                        'type' => $value['data-type'],
                        'name' => $value['label'] . ' (' . $parentKey . ')'
                    ];
                    $result[$newKey] = $value;
                }
            }

        }

        return $result;
    }

    public static function checkoutInputs(): array
    {
        $cartCheckoutHelper = CartCheckoutHelper::make();

        return array_merge(
            static::makeShortCodes($cartCheckoutHelper->getBillingAddressFields(), 'billing'),
            static::makeShortCodes($cartCheckoutHelper->getShippingAddressFields(), 'shipping')
        );
    }

    public static function conditionalInputs()
    {

    }

    public static function getCustomerShortCodesForOrder(): array
    {
        $cartCheckoutHelper = CartCheckoutHelper::make();
        $billingFields = $cartCheckoutHelper->getBillingAddressFields();

        $shippingFields = $cartCheckoutHelper->getShippingAddressFields();

        // $shortCodes = array_merge(
        //     static::collectShortcodeFromNestedTextFields($billingFields, 'billing'),
        //     static::collectShortcodeFromNestedTextFields($shippingFields, 'shipping')
        // );

        $shortCodes = array_merge(
            static::collectShortcodeFromNestedTextFields($billingFields, 'order.billing'),
            [
                '{{order.billing.city}}'      => __('City', 'fluent-cart'),
                '{{order.billing.state}}'     => __('State', 'fluent-cart'),
                '{{order.billing.postcode}}'  => __('Postcode', 'fluent-cart'),
                '{{order.billing.country}}'   => __('Country', 'fluent-cart'),
                '{{order.billing.address_1}}' => __('Address Line 1', 'fluent-cart'),
                '{{order.billing.address_2}}' => __('Address Line 2', 'fluent-cart'),
            ],
            static::collectShortcodeFromNestedTextFields($shippingFields, 'order.shipping'),
            [
                '{{order.shipping.city}}'      => __('City', 'fluent-cart'),
                '{{order.shipping.state}}'     => __('State', 'fluent-cart'),
                '{{order.shipping.postcode}}'  => __('Postcode', 'fluent-cart'),
                '{{order.shipping.country}}'   => __('Country', 'fluent-cart'),
                '{{order.shipping.address_1}}' => __('Address Line 1', 'fluent-cart'),
                '{{order.shipping.address_2}}' => __('Address Line 2', 'fluent-cart'),
            ]
        );


        return [
            'key' => 'customer',
            'title'      => 'Customer',
            'shortcodes' => $shortCodes
        ];
    }

    public static function getPaymentShortCodes(): array
    {
        $orderProperties = [

        ];

        return [
            'key' => 'payment',
            'title'      => 'Payments',
            'shortcodes' => $orderProperties,
        ];
    }

    public static function getTransactionShortCodes(): array
    {
        $orderProperties = [
            '{{transaction.total}}'                   => __('Total Amount', 'fluent-cart'),
            '{{transaction.total_formatted}}'         => __('Total Amount (Formatted)', 'fluent-cart'),
            '{{transaction.refund_amount}}'           => __('Refund Amount', 'fluent-cart'),
            '{{transaction.refund_amount_formatted}}' => __('Refund Amount (Formatted)', 'fluent-cart'),
            '{{transaction.payment_method}}'          => __('Payment Method', 'fluent-cart'),
            '{{transaction.card_last_4}}'             => __('Card Last 4', 'fluent-cart'),
            '{{transaction.card_brand}}'              => __('Card Brand', 'fluent-cart'),
            '{{transaction.status}}'                  => __('Status', 'fluent-cart'),
            '{{transaction.currency}}'                => __('Currency', 'fluent-cart'),
        ];

        return [
            'title'      => 'transaction',
            'key'        => 'settings',
            'shortcodes' => $orderProperties,
        ];
    }

    public static function getShortCodes(): array
    {
        $groups = [
            static::getCustomerShortCodesForOrder(),
            static::getOrderShortCodes(),
            static::getGeneralShortCodes(),
            static::getSettingsShortCodes(),
        ];

        $groups = apply_filters('fluent_cart/confirmation_shortcodes', $groups, []);

        $data = [
            'data' => $groups
        ];

        return $data;
    }

    public static function getOrderShortCodes(): array
    {
        return [
            'title'      => __('Order', 'fluent-cart'),
            'key'        => 'order',
            'shortcodes' => [
                '{{order.id}}'                      => __('Order ID', 'fluent-cart'),
                '{{order.customer_dashboard_link}}' => __('Customer Dashboard Link', 'fluent-cart'),
                '{{order.status}}'                  => __('Order Status', 'fluent-cart'),
                '{{order.parent_id}}'               => __('Order Parent Id', 'fluent-cart'),
                '{{order.invoice_no}}'              => __('Order Number', 'fluent-cart'),
                '{{order.fulfillment_type}}'        => __('Order Fulfillment Type', 'fluent-cart'),
                '{{order.type}}'                    => __('Order Type', 'fluent-cart'),
                '{{order.customer_id}}'             => __('Order Customer Id', 'fluent-cart'),
                '{{order.payment_method}}'          => __('Order Payment Method', 'fluent-cart'),
                '{{order.payment_method_title}}'    => __('Order Payment Method Title', 'fluent-cart'),
                '{{order.payment_status}}'          => __('Order Payment Status', 'fluent-cart'),
                '{{order.currency}}'                => __('Order Currency', 'fluent-cart'),
                '{{order.subtotal}}'                         => __('Order Subtotal', 'fluent-cart'),
                '{{order.subtotal_formatted}}'               => __('Order Subtotal (Formatted)', 'fluent-cart'),
                '{{order.discount_tax}}'                     => __('Order Discount Tax', 'fluent-cart'),
                '{{order.discount_tax_formatted}}'           => __('Order Discount Tax (Formatted)', 'fluent-cart'),
                '{{order.discount_total}}'                   => __('Order Discount Total', 'fluent-cart'),
                '{{order.discount_total_formatted}}'         => __('Order Discount Total (Formatted)', 'fluent-cart'),
                '{{order.manual_discount_total}}'            => __('Manual Discount Total', 'fluent-cart'),
                '{{order.manual_discount_total_formatted}}'  => __('Manual Discount Total (Formatted)', 'fluent-cart'),
                '{{order.coupon_discount_total}}'            => __('Coupon Discount Total', 'fluent-cart'),
                '{{order.coupon_discount_total_formatted}}'  => __('Coupon Discount Total (Formatted)', 'fluent-cart'),
                '{{order.shipping_tax}}'                     => __('Order Shipping Tax', 'fluent-cart'),
                '{{order.shipping_tax_formatted}}'           => __('Order Shipping Tax (Formatted)', 'fluent-cart'),
                '{{order.shipping_total}}'                   => __('Order Shipping Total', 'fluent-cart'),
                '{{order.shipping_total_formatted}}'         => __('Order Shipping Total (Formatted)', 'fluent-cart'),
                '{{order.tax_total}}'                        => __('Order Tax Total', 'fluent-cart'),
                '{{order.tax_total_formatted}}'              => __('Order Tax Total (Formatted)', 'fluent-cart'),
                '{{order.total_amount}}'                     => __('Order Total Amount', 'fluent-cart'),
                '{{order.total_amount_formatted}}'           => __('Order Total Amount (Formatted)', 'fluent-cart'),
                '{{order.total_paid}}'                       => __('Order Total Paid', 'fluent-cart'),
                '{{order.total_paid_formatted}}'             => __('Order Total Paid (Formatted)', 'fluent-cart'),
                '{{order.total_refund}}'                     => __('Order Total Refund', 'fluent-cart'),
                '{{order.total_refund_formatted}}'           => __('Order Total Refund (Formatted)', 'fluent-cart'),
                '{{order.rate}}'                    => __('Order Rate', 'fluent-cart'),
                '{{order.note}}'                    => __('Order Note', 'fluent-cart'),
                '{{order.ip_address}}'              => __('Order Ip Address', 'fluent-cart'),
                '{{order.completed_at}}'            => __('Order Completed At', 'fluent-cart'),
                '{{order.refunded_at}}'             => __('Order Refunded At', 'fluent-cart'),
                '{{order.uuid}}'                    => __('Order UUID', 'fluent-cart'),
                '{{order.payment_receipt}}'         => __('Payment Receipt', 'fluent-cart'),
                '{{order.payment_summary}}'         => __('Payment Summary', 'fluent-cart'),
                '{{order.downloads}}'               => __('Order Downloads', 'fluent-cart'),
                '{{order.created_at}}'              => __('Order Create Date', 'fluent-cart'),
                '{{order.item_count}}'              => __('Order Item Count', 'fluent-cart'),
                '{{order.is_digital}}'              => __('Is Digital Order', 'fluent-cart'),
            ],
        ];
    }

    public static function getEmailNotificationShortcodes(): array
    {

        $shortCodes = [
            'order'        => static::getOrderShortCodes(),
            'general'      => static::getGeneralShortCodes(),
            'customer'     => static::getCustomerShortCodesForOrder(),
            'transaction'  => static::getTransactionShortCodes(),
            'settings'     => static::getSettingsShortCodes(),
            'license'      => static::getLicenseShortCodes(),
        ];
        return apply_filters('fluent_cart/editor_shortcodes', $shortCodes);
    }


    public static function getEmailSettingsShortcodes(): array
    {
        return [
            static::getGeneralShortCodes(),
            static::getSettingsShortCodes()
        ];
    }

    public static function getItemShortCodes(): array
    {
        return [
            'title'      => __('Order Item (Loop)', 'fluent-cart'),
            'key'        => 'item',
            'shortcodes' => [
                '{{item.sl}}'           => __('Serial Number', 'fluent-cart'),
                '{{item.name}}'         => __('Product Name', 'fluent-cart'),
                '{{item.variant}}'      => __('Variant Name', 'fluent-cart'),
                '{{item.quantity}}'     => __('Quantity', 'fluent-cart'),
                '{{item.unit_price}}'           => __('Unit Price', 'fluent-cart'),
                '{{item.unit_price_formatted}}' => __('Unit Price (Formatted)', 'fluent-cart'),
                '{{item.price}}'                => __('Total Price', 'fluent-cart'),
                '{{item.price_formatted}}'      => __('Total Price (Formatted)', 'fluent-cart'),
                '{{item.subtotal}}'             => __('Subtotal', 'fluent-cart'),
                '{{item.subtotal_formatted}}'   => __('Subtotal (Formatted)', 'fluent-cart'),
                '{{item.payment_info}}' => __('Payment Info', 'fluent-cart'),
                '{{item.payment_type}}' => __('Payment Type', 'fluent-cart'),
            ],
        ];
    }

    public static function getLicenseShortCodes(): array
    {
        return [
            'title'      => __('License (Loop)', 'fluent-cart'),
            'key'        => 'license',
            'shortcodes' => [
                '{{license.sl}}'               => __('Serial Number', 'fluent-cart'),
                '{{license.key}}'              => __('License Key', 'fluent-cart'),
                '{{license.status}}'           => __('Status', 'fluent-cart'),
                '{{license.product_name}}'     => __('Product Name', 'fluent-cart'),
                '{{license.variant}}'          => __('Variant', 'fluent-cart'),
                '{{license.limit}}'            => __('Activation Limit', 'fluent-cart'),
                '{{license.activation_count}}' => __('Activation Count', 'fluent-cart'),
                '{{license.expiration_date}}'  => __('Expiration Date', 'fluent-cart'),
            ],
        ];
    }

    public static function getDownloadShortCodes(): array
    {
        return [
            'title'      => __('Download (Loop)', 'fluent-cart'),
            'key'        => 'download',
            'shortcodes' => [
                '{{download.sl}}'           => __('Serial Number', 'fluent-cart'),
                '{{download.title}}'        => __('File Title', 'fluent-cart'),
                '{{download.product_name}}' => __('Product Name', 'fluent-cart'),
                '{{download.url}}'          => __('Download URL', 'fluent-cart'),
                '{{download.file_size}}'    => __('File Size', 'fluent-cart'),
            ],
        ];
    }

    public static function getSubscriptionShortCodes(): array
    {
        return [
            'title'      => __('Subscription (Loop)', 'fluent-cart'),
            'key'        => 'subscription',
            'shortcodes' => [
                '{{subscription.sl}}'                => __('Serial Number', 'fluent-cart'),
                '{{subscription.item_name}}'         => __('Item Name', 'fluent-cart'),
                '{{subscription.status}}'            => __('Status', 'fluent-cart'),
                '{{subscription.billing_interval}}'  => __('Billing Interval', 'fluent-cart'),
                '{{subscription.recurring_amount}}'           => __('Recurring Amount', 'fluent-cart'),
                '{{subscription.recurring_amount_formatted}}' => __('Recurring Amount (Formatted)', 'fluent-cart'),
                '{{subscription.payment_info}}'      => __('Payment Info', 'fluent-cart'),
                '{{subscription.next_billing_date}}' => __('Next Billing Date', 'fluent-cart'),
                '{{subscription.bill_times}}'        => __('Bill Times', 'fluent-cart'),
                '{{subscription.bill_count}}'        => __('Bill Count', 'fluent-cart'),
                '{{subscription.trial_days}}'        => __('Trial Days', 'fluent-cart'),
                '{{subscription.expire_at}}'         => __('Expiration Date', 'fluent-cart'),
            ],
        ];
    }

    public static function getButtons()
    {
        $url = esc_url(site_url());
        return [
            'View Order' => '<a href="' . $url . '/wp-admin/admin.php?page=fluent-cart#/orders/{{order.id}}/view" style="background-color: green; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 5px;">View Order</a>'
        ];
    }
}
