<?php

namespace FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway;

use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\API\AuthorizeDotNetAPI;
use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\AuthorizeDotNetHelper;
use FluentCart\Framework\Support\Arr;

class AuthorizeDotNetRefund
{
    protected AuthorizeDotNetAPI $api;

    public function __construct()
    {
        $this->api = new AuthorizeDotNetAPI();
    }


    public function processRemoteRefund($transaction, $amount, $args = [])
    {
        $vendorChargeId = $transaction->vendor_charge_id;
        $method = $transaction->payment_method_type;
        $meta = $transaction->meta;


        if ($method != 'card') {
            return new \WP_Error(
                'invalid_refund',
                __('Refund is not supported for eCheck Payment from admin panel. Please use Authorize.Net dashboard to refund.', 'fluent-cart-pro')
            );
        }
       
        
        if (!$vendorChargeId) {
            return new \WP_Error(
                'invalid_refund',
                __('Invalid transaction ID for refund.', 'fluent-cart-pro')
            );
        }

        // Format amount for Authorize.Net (2 decimal places)
        $refundAmount = AuthorizeDotNetHelper::formatAmount($amount, $transaction->currency);
        
        $refId = substr($transaction->uuid, 0, 20);

        $transactionRequest = [
            'transactionType' => 'refundTransaction',
            'amount'          => $refundAmount,
            'currencyCode' => $transaction->currency,
        ];


        $transactionRequest['payment'] = [
            'creditCard' => [
                'cardNumber' => $transaction->card_last_4,
                'expirationDate' => 'XXXX'
            ],
        ];


        $transactionRequest['refTransId'] = $vendorChargeId;

        $data = [
            'merchantAuthentication' => $this->api->getMerchantAuthentication($transaction->payment_mode),
            'refId'                  => $refId,
            'transactionRequest'     => $transactionRequest,
        ];


        $response = $this->api->createAuthorizeNetObject('createTransactionRequest', $data, $transaction->payment_mode);


        if (is_wp_error($response)) {
            return $response;
        }

        if (!AuthorizeDotNetAPI::isSuccessResponse($response)) {
            return new \WP_Error(
                'refund_failed',
                AuthorizeDotNetAPI::extractErrorMessage($response, __('Refund could not be processed. Please check your Authorize.Net account.', 'fluent-cart-pro'))
            );
        }

        $transactionResponse = Arr::get($response, 'transactionResponse', []);
        $vendorRefundId = Arr::get($transactionResponse, 'transId');

        if (!$vendorRefundId) {
            return new \WP_Error(
                'refund_failed',
                __('Refund was initiated but no refund transaction ID was returned.', 'fluent-cart-pro')
            );
        }

        return $vendorRefundId;
    }
}

