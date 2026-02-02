<?php

namespace FluentCartPro\App\Modules\PaymentMethods\MollieGateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\API\MollieAPI;

class MollieProcessor
{
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;
        $customerBillingAddress = $paymentInstance->order->billing_address;
        $customerShippingAddress = $paymentInstance->order->shipping_address ?? $customerBillingAddress;
        $taxBehavior = $order->tax_behavior;

        $description = '';
        $lines = [];
        foreach ($order->order_items as $item) {
            $description .= $item->post_title.  ' - ' . $item->title . ' x ' . $item->quantity . ', ';

            $lineTotal = $item->line_total;

            // if ($item->tax_amount > 0 && $taxBehavior == 1) {  
            //     $lineTotal += $item->tax_amount;

            // }

            $unitPrice = $lineTotal / $item->quantity ;

            $value = $lineTotal;


            $lines[] = [
                'type' => $item->fulfillment_type,
                'description' => $item->post_title . ' - ' . $item->title,
                'quantity' => $item->quantity,
                'unitPrice' => [
                    'currency' => $transaction->currency,
                    'value'    => $this->formatAmount($unitPrice, $transaction->currency)
                ],
                // 'vatRate' => $item->tax_rate, // do not have exact rate info yet
                // 'vatAmount' => [
                //     'currency' => $transaction->currency,
                //     'value'    => $this->formatAmount($item->tax_amount / $item->quantity, $transaction->currency)
                // ],
                'totalAmount' => [
                    'currency' => $transaction->currency,
                    'value'    => $this->formatAmount($value, $transaction->currency)
                ]
            ];

        }

        // if shipping cost is there then add it to lines
        if ($order->shipping_total > 0) {
            $description .= ' + Shipping';
            $lines[] = [
                'type' => 'shipping_fee',
                'description' => __('Shipping', 'fluent-cart-pro'),
                'quantity' => 1,
                'unitPrice' => [
                    'currency' => $transaction->currency,
                    'value'    => $this->formatAmount($order->shipping_total, $transaction->currency)
                ],
                'totalAmount' => [
                    'currency' => $transaction->currency,
                    'value'    => $this->formatAmount($order->shipping_total, $transaction->currency)
                ]
            ];
        }

        $taxTotal = $order->tax_total + $order->shipping_tax;

        if ($taxTotal > 0 && $order->tax_behavior == 1) {
            $description .= ' + Tax';
            $lines[] = [
                'type' => 'surcharge',
                'description' => __('Total Tax', 'fluent-cart-pro'),
                'quantity' => 1,
                'unitPrice' => [
                    'currency' => $transaction->currency,
                    'value'    => $this->formatAmount($taxTotal, $transaction->currency)
                ],
                'totalAmount' => [
                    'currency' => $transaction->currency,
                    'value'    => $this->formatAmount($taxTotal, $transaction->currency)
                ]
            ];
        }

        $billingAddress = MollieHelper::formatAddress($fcCustomer, $customerBillingAddress);

        $shippingAddress = MollieHelper::formatAddress($fcCustomer, $customerShippingAddress);

        // remove the last ',' from description
        $description = rtrim($description, ', ');

        
       $method = ''; // will be set later if needed
       $restrictPaymentMethodsToCountry = []; // will be set later if needed
       $captureMode = 'automatic'; // default capture mode


        $paymentData = [
            'description'       =>  $description,
            'amount' => [
                'currency'      => strtoupper($transaction->currency),
                'value'         => $this->formatAmount($transaction->total, $transaction->currency)
            ],
            'redirectUrl'       => Arr::get($paymentArgs, 'success_url'),
            'cancelUrl'         => Arr::get($paymentArgs, 'cancel_url'),
            'webhookUrl'        => $this->getWebhookUrl(),
            'billingAddress'    => $billingAddress,
            'shippingAddress'   => $shippingAddress,
            'locale'            => $paymentArgs['locale'] ?? null,
            'metadata'          => [
                'order_hash'         => $order->uuid,
                'transaction_hash'   => $transaction->uuid,
            ],
            'captureMode'       => $captureMode
        ];

        $passLineItems = apply_filters('fluent_cart/mollie/pass_line_items_details', false, $order, $transaction);

        if ($passLineItems) {
            $paymentData['lines'] = $lines;
        }


        $customer = $this->createOrGetCustomer($fcCustomer);// will use in recurring
        if (!is_wp_error($customer)) {
            $paymentData['customerId']  = $customer['id'];
        }


        // Apply filters for customization
        $paymentData = apply_filters('fluent_cart/payments/mollie_payment_args', $paymentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);


        $payment = (new MollieAPI())->createMollieObject('payments', $paymentData);

        if (is_wp_error($payment)) {
            return $payment;
        }

        // Store Mollie payment ID in transaction
        $transaction->update([
            'vendor_charge_id' => $payment['id'],
            'meta'             => array_merge($transaction->meta ?? [], [
                'mollie_payment_id' => $payment['id']
            ])
        ]);

        // Get checkout URL from Mollie response
        $checkoutUrl = Arr::get($payment, '_links.checkout.href');

        if (!$checkoutUrl) {
            return new \WP_Error(
                'mollie_checkout_error',
                __('Unable to get checkout URL from Mollie', 'fluent-cart-pro')
            );
        }

        return [
            'status'       => 'success',
            'nextAction'   => 'mollie',
            'actionName'   => 'redirect',
            'message'      => __('Redirecting to Mollie payment page...', 'fluent-cart-pro'),
            'response'     => $payment,
            'payment_args' => array_merge($paymentArgs, [
                'checkout_url' => $checkoutUrl,
                'payment_id'   => $payment['id']
            ])
        ];
    }


    public function handleSubscription(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
    
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;
        $fcCustomer = $paymentInstance->order->customer;
        $customerBillingAddress = $paymentInstance->order->billing_address;
        $customerShippingAddress = $paymentInstance->order->shipping_address ?? $customerBillingAddress;

        $billingAddress = MollieHelper::formatAddress($fcCustomer, $customerBillingAddress);

        $shippingAddress = MollieHelper::formatAddress($fcCustomer, $customerShippingAddress);

        $customer = $this->createOrGetCustomer($fcCustomer);// will use in recurring
        if (is_wp_error($customer)) {
            return $customer;
        }

        $subscription->query()->update([
            'vendor_customer_id' => $customer['id']
        ]);

        if ($order->type == 'renewal') {
            $trialDays = (int) $subscription->getReactivationTrialDays();
            if ($trialDays > 0) {
                $amount = 0;
            } else {
                $amount = $subscription->getCurrentRenewalAmount();
            }

            $firstPayment = [
                'amount' => [
                    'currency' => strtoupper($transaction->currency),
                    'value' => $this->formatAmount($amount, $transaction->currency) 
                ],
                'customerId' => $customer['id'],
                'description' => $subscription->item_name . ' - First Payment',
                'sequenceType' => 'first',
                'redirectUrl' => Arr::get($paymentArgs, 'success_url'),
                'cancelUrl' => Arr::get($paymentArgs, 'cancel_url'),
                'webhookUrl' => $this->getWebhookUrl(),
                'locale' => $paymentArgs['locale'] ?? null,
                'billingAddress'    => $billingAddress,
                'shippingAddress'   => $shippingAddress,
                'metadata' => [
                    'order_hash' => $order->uuid,
                    'transaction_hash' => $transaction->uuid,
                    'subscription_hash' => $subscription->uuid,
                ]
            ];
            
        } else {
            $firstPayment = [
                'amount' => [
                    'currency' => strtoupper($transaction->currency),
                    'value' => $this->formatAmount($transaction->total, $transaction->currency) 
                ],
                'customerId' => $customer['id'],
                'description' => $subscription->item_name . ' - First Payment',
                'sequenceType' => 'first',
                'redirectUrl' => Arr::get($paymentArgs, 'success_url'),
                'cancelUrl' => Arr::get($paymentArgs, 'cancel_url'),
                'webhookUrl' => $this->getWebhookUrl(),
                'locale' => $paymentArgs['locale'] ?? null,
                'billingAddress'    => $billingAddress,
                'shippingAddress'   => $shippingAddress,
                'metadata' => [
                    'order_hash' => $order->uuid,
                    'transaction_hash' => $transaction->uuid,
                    'subscription_hash' => $subscription->uuid,
                ]
            ];
        }


        
        $payment = (new MollieAPI())->createMollieObject('payments', $firstPayment);

        if (is_wp_error($payment)) {
            return $payment;
        }

        // Store Mollie payment ID in transaction
        $transaction->update([
            'vendor_charge_id' => $payment['id']
        ]);

        return [
            'status' => 'success',
            'nextAction' => 'mollie',
            'actionName' => 'redirect',
            'message' => __('Redirecting to Mollie payment page...', 'fluent-cart-pro'),
            'response' => $payment,
            'payment_args' => array_merge($paymentArgs, [
                'checkout_url' => Arr::get($payment, '_links.checkout.href'),
                'payment_id' => $payment['id']
            ])
        ];
    }
  
    public function formatAmount($amount, $currency)
    {
        $zeroDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP', 'TWD'];
        
        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return number_format($amount, 0, '.', '');
        }

        return number_format($amount / 100, 2, '.', '');
    }

    public function getWebhookUrl()
    {
        return apply_filters('fluent_cart/mollie/webhook_url', site_url('?fluent-cart=fct_payment_listener_ipn&method=mollie'));
    }

    public function createOrGetCustomer($fcCustomer)
    {
        $mode = (new StoreSettings())->get('order_mode');
        $mollieCustomerId = $fcCustomer->getMeta('mollie_customer_id_' . $mode);
        
        if ($mollieCustomerId) {
            $mollieCustomer = (new MollieAPI())->getMollieObject('customers/' . $mollieCustomerId, []);  
            if (!is_wp_error($mollieCustomer)) {
                return $mollieCustomer;
            }
        }

        $customerData = [
            'name' => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            'email' => $fcCustomer->email
        ];

        $response = (new MollieAPI())->createMollieObject('customers', $customerData);

        if (is_wp_error($response)) {
            return $response;
        }

        $fcCustomer->updateMeta('mollie_customer_id_' . $mode, $response['id'] ?? '');

        return $response;
    }
}

