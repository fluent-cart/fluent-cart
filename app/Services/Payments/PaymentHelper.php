<?php

namespace FluentCart\App\Services\Payments;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Support\Arr;

class PaymentHelper
{
    public string $slug = '';

    public function __construct($slug)
    {
        $this->slug = $slug;
    }

    public function listenerUrl($args = [])
    {
        $listener = '?fct_payment_listener=1&method=' . $this->slug;
        return apply_filters('fluent_cart_ipn_url_' . $this->slug, [
            'listener_url' => site_url($listener)
        ]);
    }

    public function successUrl($uuid, $args = null)
    {
        $queryArgs = array_merge(
            array(
                'method'       => $this->slug,
                'trx_hash'     => $uuid,
                'fct_redirect' => 'yes'
            ),
            is_array($args) ? $args : []
        );

        return add_query_arg($queryArgs, (new StoreSettings())->getReceiptPage());
    }

    public static function getCustomPaymentLink($orderHash): string
    {
            return wp_sanitize_redirect(
                URL::appendQueryParams(home_url('/?fluent-cart=custom_checkout'), [
                'order_hash' => $orderHash,
            ]));
    }

    /*
     *This will be hooked from basePaymentMethod
     * validate payment method is active for checkout items, before order creation
    */
    public static function validateAndGetPayMethod($cartCheckoutHelper, $orderData, $extraCharge = 0)
    {
        $paymentMethod = Arr::get($orderData, 'others._fct_pay_method');
        $isZeroPayment = $cartCheckoutHelper->getItemsAmountTotal(false, false) + $extraCharge <= 0;
        if ($isZeroPayment && $cartCheckoutHelper->hasSubscription() !== 'yes') {
            $paymentMethod = apply_filters('fluent_cart/default_payment_method_for_zero_payment', 'offline_payment', []);
        }

        if (!GatewayManager::has($paymentMethod)) {
            wp_send_json([
                    'status'  => 'failed',
                    'message' => __('No valid payment method found!', 'fluent-cart'),
                    'data'    => []
                ], 423
            );
        };

        $gateway = GatewayManager::getInstance($paymentMethod);
        $status = $gateway->validatePaymentMethod([
            'isValid'       => false,
            'reason'        => __('No payment method found!', 'fluent-cart'),
            'isZeroPayment' => $isZeroPayment
        ]);

        if (!Arr::get($status, 'isValid')) {
            wp_send_json(
                [
                    'status'  => 'failed',
                    'message' => Arr::get($status, 'reason'),
                    'data'    => []
                ], 423
            );
        }

        return $paymentMethod;
    }

    public static function updateTransactionRefundedTotal($parentTransaction, $refundedAmount)
    {
        // update the transaction. only update refunded_total, in meta, if exist add
        $meta = $parentTransaction->meta;
        $alreadyRefunded = Arr::get($meta, 'refunded_total', 0);
        $meta['refunded_total'] = $alreadyRefunded + $refundedAmount;
        $parentTransaction->meta = $meta;
        $parentTransaction->save();
    }

//    public static function updateOrderRefundedTotal($order, $refundedAmount, &$type): void
//    {
//        // update order data
//        $netOrderPaidAmount = $order->total_paid - $order->total_refunded;
//        $isFullRefund = $refundedAmount == $netOrderPaidAmount;
//
//        if ($isFullRefund) {
//            $order->payment_status = Status::PAYMENT_REFUNDED;
//            $type = 'full';
//        } else {
//            $order->payment_status = Status::PAYMENT_PARTIALLY_REFUNDED;
//            $type = 'partial';
//        }
//        $order->total_refund += $refundedAmount;
//        $order->save();
//    }

    /**
     * @param string $paymentMethod
     * @param array $paymentMethodDetails
     * @param array $additionalData
     * @return array
     * parse payment method details and return a common format
     * filter hook: 'fluent_cart/payments/parse_payment_method_details'
     */
    public static function parsePaymentMethodDetails($paymentGateway, $paymentMethodDetails, $additionalData = []): array
    {
        $billingInfo = [
            'method'          => $paymentGateway,
            'type'            => null,
            'details'         => [],
            'billing_details' => [
                'name'    => null,
                'email'   => null,
                'phone'   => null,
                'address' => [
                    'country'     => null,
                    'postal_code' => null,
                    'line1'       => null,
                    'line2'       => null,
                    'city'        => null,
                    'state'       => null,
                ]
            ]
        ];

        if ($paymentGateway === 'stripe') {
            $type = Arr::get($paymentMethodDetails, 'type', 'card');
            $billingInfo['type'] = $type;

            if ($type === 'card') {
                $billingInfo['details'] = [
                    'type'              => 'card',
                    'brand'             => sanitize_text_field(Arr::get($paymentMethodDetails, 'card.brand')),
                    'last_4'            => sanitize_text_field(Arr::get($paymentMethodDetails, 'card.last4')),
                    'exp_month'         => sanitize_text_field(Arr::get($paymentMethodDetails, 'card.exp_month')),
                    'exp_year'          => sanitize_text_field(Arr::get($paymentMethodDetails, 'card.exp_year')),
                    'fingerprint'       => sanitize_text_field(Arr::get($paymentMethodDetails, 'card.fingerprint')),
                    'payment_method_id' => sanitize_text_field(Arr::get($paymentMethodDetails, 'id'))
                ];
            } else {
                // For other Stripe payment methods (ACH, SEPA, etc.)
                $billingInfo['details'] = [
                    'payment_method_id' => sanitize_text_field(Arr::get($paymentMethodDetails, 'id')),
                    'type'     => Arr::get($paymentMethodDetails, $type, [])
                ];
            }

            // Common billing details for Stripe
            $billingInfo['billing_details'] = [
                'name'    => sanitize_text_field(Arr::get($paymentMethodDetails, 'billing_details.name')),
                'email'   => sanitize_email(Arr::get($paymentMethodDetails, 'billing_details.email', '')),
                'phone'   => sanitize_text_field(Arr::get($paymentMethodDetails, 'billing_details.phone')),
                'address' => [
                    'country'     => sanitize_text_field(Arr::get($paymentMethodDetails, 'billing_details.address.country')),
                    'postal_code' => sanitize_text_field(Arr::get($paymentMethodDetails, 'billing_details.address.postal_code')),
                    'line1'       => sanitize_text_field(Arr::get($paymentMethodDetails, 'billing_details.address.line1')),
                    'line2'       => sanitize_text_field(Arr::get($paymentMethodDetails, 'billing_details.address.line2')),
                    'city'        => sanitize_text_field(Arr::get($paymentMethodDetails, 'billing_details.address.city')),
                    'state'       => sanitize_text_field(Arr::get($paymentMethodDetails, 'billing_details.address.state')),
                ]
            ];

        } elseif ($paymentGateway === 'paypal') {
            $billingInfo['type'] = 'standard';
            $billingInfo['details'] = [
                'email'    => sanitize_email(Arr::get($paymentMethodDetails, 'email', '')),
                'payer_id' => sanitize_text_field(Arr::get($paymentMethodDetails, 'payer_id')),
            ];

            // PayPal billing details
            $billingInfo['billing_details'] = [
                'name'    => sanitize_text_field(Arr::get($paymentMethodDetails, 'name')),
                'email'   => sanitize_email(Arr::get($paymentMethodDetails, 'email', '')),
                'phone'   => sanitize_text_field(Arr::get($paymentMethodDetails, 'phone')),
                'address' => [
                    'country'     => sanitize_text_field(Arr::get($paymentMethodDetails, 'address.country_code')),
                    'postal_code' => sanitize_text_field(Arr::get($paymentMethodDetails, 'address.postal_code')),
                    'line1'       => sanitize_text_field(Arr::get($paymentMethodDetails, 'address.address_line_1')),
                    'line2'       => sanitize_text_field(Arr::get($paymentMethodDetails, 'address.address_line_2')),
                ]
            ];
        }

        return $billingInfo;
    }


    public static function getIntervalDays($interval = ''): int
    {
        if ($interval === 'yearly') {
            $days = 365;
        } elseif ($interval === 'monthly') {
            $days = 30;
        } elseif ($interval === 'weekly') {
            $days = 7;
        } else {
            $days =  1;
        }
        return $days;
    }

}
