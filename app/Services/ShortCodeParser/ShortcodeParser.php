<?php

namespace FluentCart\App\Services\ShortCodeParser;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Payments\PaymentReceipt;
use FluentCart\App\Services\WpMetaHelper;
use FluentCart\Framework\Support\Arr;

class ShortcodeParser
{

    /*
     *
     *  @param $content string|array
     *  @param $order object with customer FluentCart\Api\Orders; getOrderWithCustomer;
     *  @return string|array
     */
    public static function parse($content, $parsable)
    {

        $transaction = Arr::get($parsable, 'transaction', []);
        $order = Arr::get($parsable, 'order', []);

        if (is_array($content)) {
            return static::arrayIterator($content, $parsable);
        }

        $parsableItems = self::nestedArrayItems($content);

        if (!$parsableItems) {
            return $content;
        }

        if (!is_array($parsableItems)) {
            return $parsableItems;
        }

        $formattedParsables = array();

        if (!is_array($parsableItems)) {
            return $parsableItems;
        }

        foreach ($parsableItems as $parsableKey => $parsableItem) {
            // Get Parsed Group
            $group = strtok($parsableItem, '.:');
            $itemExt = str_replace(array($group . '.', $group . ':'), '', $parsableItem);
            $formattedParsables[$group][$parsableKey] = $itemExt;
        }

        $wpParser = Arr::only(
            $formattedParsables,
            array(
                'wp',
                'other'
            )
        );

        $formattedInputs = Arr::only(
            $formattedParsables,
            array(
                'order',
            )
        );

        $orderAddressCodes = Arr::only(
            $formattedParsables,
            array(
                'billing',
                'shipping'
            )
        );

        $otherShortCodes = Arr::only(
            $formattedParsables,
            array(
                'payment',
            )
        );

        $userShortCodes = Arr::only(
            $formattedParsables,
            array(
                'user'
            )
        );

        $transactionShortCodes = Arr::only(
            $formattedParsables,
            array(
                'transaction'
            )
        );

        $customerShortCodes = Arr::only(
            $formattedParsables,
            ['customer']
        );

        $parsedOthers = static::parseOthers($otherShortCodes, $order);
        $parsedInputs = static::parseInputFields($formattedInputs, $order);
        $parsedWp = isset($formattedParsables['wp']) ? static::parseWPFields($wpParser, $order) : [];
        $parsedAddress = static::parseAddressFields($orderAddressCodes, Arr::get($order, 'customer', []));
        $parsedUser = static::parseUserFields($userShortCodes, Arr::get($parsable, 'user'));
        $parsedTransaction = isset($formattedParsables['transaction']) ? static::parseTransaction($transactionShortCodes, $transaction) : [];
        $parsedCustomer = isset($formattedParsables['customer']) ? static::parseCustomerFields($customerShortCodes, Arr::get($order, 'customer', [])) : [];

        $parsedData = array_merge(
            $parsedInputs,
            $parsedWp,
            $parsedAddress,
            $parsedOthers,
            $parsedUser,
            $parsedTransaction,
            $parsedCustomer
        );

        $formattedParsedItems = [];
        foreach ($parsedData as $parsedKey => $parseItem) {
            if (is_array($parseItem)) {
                $parseItem = implode(', ', $parseItem);
            }
            $formattedParsedItems[$parsedKey] = $parseItem;
        }
   
        return self::replaceValue($content, $formattedParsedItems);
    }

    public static function arrayIterator($contentArray, $order)
    {
        foreach ($contentArray as $key => $val) {
            if (!is_array($val)) {
                $contentArray[$key] = static::parse($val, $order);
            } else {
                $contentArray[$key] = static::arrayIterator($val, $order);
            }
        }
        return $contentArray;
    }

    public static function parseUserFields($userShortCodes, $user = null): array
    {
        $customData = [
            'password_reset_link' => function ($user) {
                $user_data = get_userdata($user->ID);
                $user_login = $user_data->user_login;
                $key = get_password_reset_key($user_data);
                if (is_wp_error($key)) {
                    return __("Error generating password reset key", 'fluent-cart');
                }

                $locale = get_user_locale($user_data);

                return '<a href="' . network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user_login), 'login') . '&wp_lang=' . $locale . '"> ' . __('Reset', 'fluent-cart') . ' </a>';

            }
        ];
        global $current_user;

        if (!($user instanceof \WP_User)) {
            $user = $current_user;
        }

        $parsedData = array();
        if (!$user) {
            return $parsedData;
        }
        foreach ($userShortCodes as $groupKey => $values) {
            foreach ($values as $placeholder => $targetItem) {
                if (isset($customData[$targetItem])) {
                    $parsedData[$placeholder] = $customData[$targetItem]($user);
                } else {
                    $parsedData[$placeholder] = $user->{$targetItem};
                }

            }
        }
        return $parsedData;

    }

    public static function parseCustomerFields($customerShortCodes, $customer): array
    {
        $parsedData = array();
        foreach ($customerShortCodes as $groupKey => $values) {
            foreach ($values as $placeholder => $targetItem) {
                if ($targetItem === 'first_name') {
                    $val = Arr::get($customer, 'first_name', null);
                }
                if ($targetItem === 'last_name') {
                    $val = Arr::get($customer, 'last_name', null);
                }
                $parsedData[$placeholder] = $val;
            }
        }
        return $parsedData;
    }

    public static function parseOthers($otherShortCodes, $order): array
    {
        $parsedData = array();
        foreach ($otherShortCodes as $groupKey => $values) {
            foreach ($values as $placeholder => $targetItem) {
                if ($targetItem === 'summary') {
                    $val = static::getSummary($order);
                }
                if ($targetItem === 'receipt') {
                    $val = static::getReceipt($order);
                }

                if (!empty($val)) {
                    $parsedData[$placeholder] = $val;
                }

            }
        }
        return $parsedData;
    }

    public static function parseTransaction($transactionShortCodes, $transaction): array
    {
        $parsedData = [];
        foreach ($transactionShortCodes as $groupKey => $values) {
            foreach ($values as $placeholder => $targetItem) {
                if ($targetItem === 'refund_amount') {
                    $refund_amount = Helper::toDecimal(Arr::get($transaction, 'refund_amount', false));
                    $val = Helper::toDecimal($refund_amount);
                }
                if ($targetItem === 'created_at') {
                    $val = $transaction['created_at'];
                }

                if (!empty($val)) {
                    $parsedData[$placeholder] = $val;
                }

            }
        }
        return $parsedData;
    }

    public static function getSummary($order)
    {
        $paymentReceipt = new PaymentReceipt($order);
        ob_start();
        do_action('fluent_cart/views/checkout_order_summary', compact('order', 'paymentReceipt'));
        return ob_get_clean();
    }

    public static function getReceipt($order)
    {
        $paymentReceipt = new PaymentReceipt($order);
        ob_start();
        do_action('fluent_cart/views/checkout_order_receipt', compact('order', 'paymentReceipt'));
        return ob_get_clean();
    }

    public static function replaceValue($parsedItems, $shortcodeValues)
    {
        if (is_array($parsedItems)) {
            foreach ($parsedItems as $key => $value) {
                if (!is_array($value)) {
                    $parsedItems[$key] = str_replace(array_keys($shortcodeValues), array_values($shortcodeValues), $value);
                } else {
                    $parsedItems[$key] = self::replaceValue($value, $shortcodeValues);
                }
            }
        } else {
            $parsedItems = str_replace(array_keys($shortcodeValues), array_values($shortcodeValues), $parsedItems);
        }
        return $parsedItems;
    }

    public static function parseInputFields($placeholders, $order): array
    {
        $order = $order->load('customer', 'billing_address', 'shipping_address')->toArray();
        $order['billing'] = Arr::get($order, 'billing_address');
        $order['shipping'] = Arr::get($order, 'shipping_address');

        unset($order['billing_address']);
        unset($order['shipping_address']);

        $storeSettings = new StoreSettings();
        $profilePage = $storeSettings->getCustomerProfilePage();
        $parsedData = [];

        foreach ($placeholders as $groupKey => $values) {
            foreach ($values as $placeholder => $targetItem) {
                if ($groupKey == 'order') {
                    if ($targetItem === 'customer_dashboard_link') {
                        if (!empty($profilePage)) {
                            $parsedData[$placeholder] = "<a style='color: #017EF3; text-decoration: none;' href='" . "$profilePage#/order/" . Arr::get($order, 'uuid') . "'>" . Arr::get($order, 'id') . "</a>";
                        } else {
                            $parsedData[$placeholder] = Arr::get($order, 'id');
                        }
                    } else if ($targetItem === 'total_amount') {
                        $parsedData[$placeholder] = Helper::toDecimal(Arr::get($order, 'total_amount'), false);
                    } elseif ($targetItem === 'total_paid') {
                        $parsedData[$placeholder] = Helper::toDecimal(Arr::get($order, 'total_paid'), false);
                    } else if ($targetItem === 'tax_total') {
                        $parsedData[$placeholder] = Helper::toDecimal(Arr::get($order, 'tax_total'), false);
                    } else if ($targetItem === 'shipping_total') {
                        $parsedData[$placeholder] = Helper::toDecimal(Arr::get($order, 'shipping_total'), false);
                    }  elseif ($targetItem === 'manual_discount_total') {
                        $parsedData[$placeholder] = Helper::toDecimal(Arr::get($order, 'manual_discount_total'), false);
                    } elseif ($targetItem === 'coupon_discount_total') {
                        $parsedData[$placeholder] = Helper::toDecimal(Arr::get($order, 'coupon_discount_total'), false);
                    } elseif ($targetItem === 'shipping_tax') {
                        $parsedData[$placeholder] = Helper::toDecimal(Arr::get($order, 'shipping_tax'), false);
                    } elseif ($targetItem === 'total_refund') {
                        $parsedData[$placeholder] = Helper::toDecimal(Arr::get($order, 'total_refund'), false);
                    } else {
                        $parsedData[$placeholder] = Arr::get($order, $targetItem);
                    }

                } elseif ($groupKey == 'customer') {
                    $parsedData[$placeholder] = Arr::get($order, 'customer.' . $targetItem, []);
                } elseif ($groupKey == 'order_items') {
                    $parsedData[$placeholder] = Arr::get($order, 'order_items.' . $targetItem);
                }
            }
        }
        return $parsedData;
    }

    public static function parseShortcode($parsableItems): array
    {
        $parsable = [];
        preg_replace_callback('/{+(.*?)}/', function ($matches) use (&$parsable) {
            $parsable[$matches[0]] = $matches[1];
        }, $parsableItems);
        return $parsable;
    }

    public static function nestedArrayItems($parsableItems): array
    {
        if (!is_array($parsableItems)) {
            return self::parseShortcode($parsableItems);
        }

        $parsableData = [];
        // checking item has child array elements
        foreach ($parsableItems as $parsable) {
            // checking if there is another child array, checking 2 levels deep
            if (is_array($parsable) && count($parsable)) {
                foreach ($parsable as $parsableItem) {
                    $arrayParsed = self::parseShortcode($parsableItem);
                    $parsableData = array_merge($parsable, $arrayParsed);
                }
            } else {
                $arrayParsed = self::parseShortcode($parsable);
                $parsableData = array_merge($parsable, $arrayParsed);
            }
        }

        return $parsableData;
    }

    public static function parseAddressFields($parsable, $data): array
    {

        if (empty($data)) {
            return [];
        }
        $billing = Arr::get($data, 'billing_address.0');
        $shipping = Arr::get($data, 'shipping_address.0');

        $parsedData = [];
        foreach ($parsable as $groupKey => $values) {
            foreach ($values as $placeholder => $targetItem) {
                if ($groupKey === 'billing' && isset($billing)) {
                    $val = Arr::get($billing, $targetItem);
                    if ($targetItem === 'first_name' || $targetItem === 'last_name') {
                        $val = static::splitName($billing, $targetItem);
                    }
                    $parsedData[$placeholder] = $val;
                } elseif ($groupKey === 'shipping' && isset($shipping)) {
                    $val = Arr::get($shipping, $targetItem);
                    if ($targetItem === 'first_name' || $targetItem === 'last_name') {
                        $val = static::splitName($shipping, $targetItem);
                    }
                    $parsedData[$placeholder] = $val;
                }
            }
        }
        return $parsedData;
    }

    public static function splitName($data, $key = 'first_name')
    {
        $names = explode(" ", Arr::get($data, 'name'));
        return $key === 'first_name' ? $names[0] : $names[1];
    }

    public static function parseWPFields($placeHolders, $order): array
    {
        $parsedData = array();
        $metaData = new WpMetaHelper($order);
        foreach ($placeHolders as $groupKey => $values) {
            foreach ($values as $placeholder => $targetItem) {
                if ($groupKey == 'wp') {
                    $parsedData[$placeholder] = $metaData->getWPValues($targetItem);
                } elseif ($groupKey == 'user_meta') {
                    $parsedData[$placeholder] = $metaData->getuserMeta($targetItem);
                } elseif ($groupKey == 'other') {
                    $parsedData[$placeholder] = $metaData->getOtherData($targetItem);
                }
            }
        }
        return $parsedData;
    }
}
