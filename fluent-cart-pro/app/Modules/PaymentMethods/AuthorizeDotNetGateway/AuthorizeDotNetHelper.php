<?php

namespace FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Events\Order\OrderPaymentFailed;
use FluentCart\Api\CurrencySettings;
use FluentCart\Framework\Support\Arr;

class AuthorizeDotNetHelper
{
 
    public static function extractPaymentDetails(array $data, string $fallbackMethod = 'card'): array
    {
        $method = $fallbackMethod;
        $brand = null;
        $lastFour = null;
        $accountType = null;
        $cardExpirationDate = null;

        // Normalize transactionResponse payload structure
        if (Arr::has($data, 'transactionResponse')) {
            $data = Arr::get($data, 'transactionResponse', []);
        }

        if (Arr::has($data, 'accountType') || Arr::has($data, 'accountNumber')) {
            $accountType = (string) Arr::get($data, 'accountType');
            $accountNumber = (string) Arr::get($data, 'accountNumber');

            if (!empty($accountType)) {
                if (stripos($accountType, 'check') !== false || stripos($accountType, 'echeck') !== false) {
                    $method = 'echeck';
                    $brand = 'eCheck';
                } else {
                    $method = 'card';
                    $brand = $accountType;
                }
            }

            if ($accountNumber) {
                $lastFour = substr(preg_replace('/[^0-9]/', '', $accountNumber), -4) ?: null;
            }
        }

        // Transaction detail API payload
        if (Arr::has($data, 'payment')) {
            $payment = Arr::get($data, 'payment', []);
            if (Arr::has($payment, 'creditCard')) {
                $card = Arr::get($payment, 'creditCard', []);
                $method = 'card';
                $brand = Arr::get($card, 'cardType') ?: $brand;
                $lastFour = Arr::get($card, 'cardNumber') ? substr(preg_replace('/[^0-9]/', '', Arr::get($card, 'cardNumber')), -4) : $lastFour;
            } elseif (Arr::has($payment, 'bankAccount')) {
                $bank = Arr::get($payment, 'bankAccount', []);
                $method = 'echeck';
                $brand = 'eCheck';
                $accountType = Arr::get($bank, 'accountType') ?: $accountType;
                $lastFour = Arr::get($bank, 'accountNumber') ? substr(preg_replace('/[^0-9]/', '', Arr::get($bank, 'accountNumber')), -4) : $lastFour;
            }
        }

        return [
            'method'      => $method,
            'brand'       => $brand,
            'last_four'   => $lastFour,
            'account_type'=> $accountType,
            'card_expiration_date' => $cardExpirationDate,
        ];
    }

    public static function formatAmount(int $amountInCents, string $currency): string
    {
        $currency = strtoupper($currency);
        $zeroDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP', 'TWD'];

        if (in_array($currency, $zeroDecimalCurrencies, true)) {
            return (string) $amountInCents;
        }

        return number_format($amountInCents / 100, 2, '.', '');
    }

 
    public static function markTransactionSucceeded(OrderTransaction $transaction, array $payload, array $details = []): void
    {
        $order = $transaction->order;
        if (!$order) {
            return;
        }

        $paymentDetails = self::extractPaymentDetails($payload, Arr::get($details, 'method', 'card'));

        $vendorChargeId = Arr::get($payload, 'id')
            ?: Arr::get($payload, 'transId')
            ?: Arr::get($payload, 'transactionId')
            ?: $transaction->vendor_charge_id;

        $invoiceNumber = Arr::get($payload, 'invoiceNumber')
            ?: Arr::get($payload, 'order.invoiceNumber');

        $transaction->fill(array_filter([
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id'    => $vendorChargeId,
            'payment_method_type' => $paymentDetails['method'],
            'card_last_4'         => $paymentDetails['last_four'],
            'card_brand'          => $paymentDetails['method'] === 'card' ? $paymentDetails['brand'] : null,
            'meta'                => array_merge($transaction->meta ?? [], [
                'authnet' => array_merge(
                    Arr::only($payload, ['authCode', 'batchId', 'submitTimeUTC', 'invoiceNumber', 'transId']),
                    $invoiceNumber ? ['invoiceNumber' => $invoiceNumber] : [],
                    ['raw' => $payload]
                )
            ])
        ]));

        $transaction->save();

        (new StatusHelper($order))->syncOrderStatuses($transaction);
    }


    public static function markTransactionPending(OrderTransaction $transaction, array $payload, string $note = ''): void
    {
        $order = $transaction->order;
        if (!$order) {
            return;
        }

        $transaction->fill([
            'status' => Status::TRANSACTION_PENDING,
            'meta'   => array_merge($transaction->meta ?? [], [
                'authnet_pending_note' => $note,
                'authnet_pending_payload' => $payload,
            ])
        ]);
        $transaction->save();

        fluent_cart_add_log(
            __('Authorize.Net Pending', 'fluent-cart-pro'),
            $note ?: __('Authorize.Net marked transaction as pending review.', 'fluent-cart-pro'),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]
        );
    }

    public static function markTransactionFailed(OrderTransaction $transaction, string $message, array $context = []): void
    {
        $order = $transaction->order;
        if (!$order) {
            return;
        }

        $transaction->fill([
            'status' => Status::TRANSACTION_FAILED,
            'meta'   => array_merge($transaction->meta ?? [], [
                'authnet_failed_reason' => $message,
                'authnet_failed_context' => $context,
            ])
        ]);
        $transaction->save();

        $oldStatus = $order->payment_status;

        $order->update([
            'payment_status' => Status::PAYMENT_FAILED,
            'status'         => Status::ORDER_FAILED,
        ]);

       (new OrderPaymentFailed($order,  $transaction, $oldStatus, Status::PAYMENT_FAILED, $message))->dispatch();
    }

    public static function translateToFluentCartStatus($authnetStatus)
    {
        $statusMap = [
            'active'   => Status::SUBSCRIPTION_ACTIVE,
            'expired'  => Status::SUBSCRIPTION_EXPIRED,
            'suspended' => Status::SUBSCRIPTION_PAUSED,
            'canceled' => Status::SUBSCRIPTION_CANCELED,
            'terminated' => Status::SUBSCRIPTION_CANCELED,
            'expiring' => Status::SUBSCRIPTION_EXPIRING,
        ];

        return $statusMap[strtolower($authnetStatus)] ?? Status::SUBSCRIPTION_ACTIVE;
    }

    public static function getInvoiceNumber(OrderTransaction $transaction): string
    {
        $raw = substr($transaction->uuid, 0, 20);
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
    }

    public static function createOrUpdateIpnRefund($refundData, $parentTransaction)
    {
        $allRefunds = OrderTransaction::query()
            ->where('order_id', $refundData['order_id'])
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        if ($allRefunds->isEmpty()) {
            // This is the first refund for this order
            $createdRefund = OrderTransaction::query()->create($refundData);
            return $createdRefund instanceof OrderTransaction ? $createdRefund : null;
        }

        $currentRefundAuthNetId = Arr::get($refundData, 'vendor_charge_id', '');

        $existingLocalRefund = null;
        foreach ($allRefunds as $refund) {
            if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                if ($refund->total != $refundData['total']) {
                    $refund->fill($refundData);
                    $refund->save();
                }
                // This refund already exists
                return $refund;
            }

            if (!$refund->vendor_charge_id) { // This is a local refund without vendor charge id
                $refundAuthNetId = Arr::get($refund->meta, 'authnet_refund_id', '');
                $isRefundMatched = $refundAuthNetId == $currentRefundAuthNetId;

                // This is a local refund without vendor charge id, we will update it
                if ($refund->total == $refundData['total'] && $isRefundMatched) {
                    $existingLocalRefund = $refund;
                }
            }
        }

        if ($existingLocalRefund) {
            $existingLocalRefund->fill($refundData);
            $existingLocalRefund->save();
            return $existingLocalRefund;
        }

        // Create new refund
        $createdRefund = OrderTransaction::query()->create($refundData);
        return $createdRefund instanceof OrderTransaction ? $createdRefund : null;
    }


    public static function formatAddress($address, $fcCustomer, $isBilling = true): array
    {
        if (!$address) {
            return [
                'firstName' => '',
                'lastName'  => '',
                'address'   => '',
                'city'      => '',
                'state'     => '',
                'zip'       => '',
                'country'   => '',
            ];
        }

        $firstName = $fcCustomer->first_name;
        $lastName = $fcCustomer->last_name;

        return array_filter([
            'firstName' => mb_substr($firstName, 0, 50),
            'lastName'  => mb_substr($lastName, 0, 50),
            'address'   => mb_substr(trim($address->address_1 . ' ' . $address->address_2), 0, 60),
            'city'      => mb_substr($address->city, 0, 40),
            'state'     => mb_substr($address->state, 0, 40),
            'zip'       => mb_substr($address->postcode, 0, 20),
            'country'   => mb_substr($address->country, 0, 2)
        ]);
    }

    public static function checkCurrencySupport()
    {
        $supportedCurrencies = ['USD', 'CAD', 'GBP', 'EUR', 'AUD', 'NZD', 'CHF', 'JPY', 'DKK', 'SEK', 'NOK', 'ZAR', 'PLN'];
        $supportedCurrencies = apply_filters('fluent_cart/authorize_dot_net_supported_currencies', $supportedCurrencies);
        $currentCurrency = strtoupper(CurrencySettings::get('currency'));
        return in_array($currentCurrency, $supportedCurrencies);
    }
}
