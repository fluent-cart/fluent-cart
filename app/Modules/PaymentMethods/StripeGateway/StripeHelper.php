<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Money;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\Framework\Support\Arr;

class StripeHelper
{
    /**
     * Stripe customer to bill $customer with.
     *
     * Stripe bills subscriptions and invoices in one currency per customer. When
     * $currency is given and the stored `stripe_customer_id` already bills another
     * currency, a customer dedicated to that currency is used instead, stored in
     * `stripe_customer_id_{currency}` meta (lowercase ISO code, e.g. stripe_customer_id_eur).
     * Leave $currency empty for PaymentIntents and SetupIntents, which have no such limit.
     *
     * @param Customer $customer
     * @param string $currency order currency, for calls that create a Stripe subscription
     * @return array|\WP_Error Stripe customer object
     */
    public static function createOrGetStripeCustomer(Customer $customer, $currency = '')
    {
        $currency = strtolower((string)$currency);
        $currencyMetaKey = $currency ? 'stripe_customer_id_' . $currency : '';

        if ($currencyMetaKey) {
            $dedicatedId = $customer->getMeta($currencyMetaKey, false);
            $dedicated = $dedicatedId ? self::getUsableStripeCustomer($dedicatedId, $customer, $currency) : null;
            if ($dedicated) {
                return $dedicated;
            }
        }

        // The legacy id keeps serving every currency it can bill, so single-currency stores are unchanged.
        $legacyIsValid = false;
        $existingStripeCustomerId = $customer->getMeta('stripe_customer_id', false);
        if ($existingStripeCustomerId) {
            $existingStripeCustomer = self::getUsableStripeCustomer($existingStripeCustomerId, $customer);

            if ($existingStripeCustomer) {
                if (self::stripeCustomerCanBill($existingStripeCustomer, $currency)) {
                    return $existingStripeCustomer;
                }
                $legacyIsValid = true;
            }
        }

        $customerInfo = array_filter([
            'name'    => $customer->full_name,
            'email'   => $customer->email,
            'phone'   => $customer->phone ?? '',
            'address' => array_filter([
                'city'        => $customer->city ?? '',
                'country'     => $customer->country ?? '',
                'postal_code' => $customer->postcode ?? '',
                'state'       => $customer->state ?? '',
            ])
        ]);

        $newStripeCustomer = (new API())->createStripeObject('customers', $customerInfo);

        if (is_wp_error($newStripeCustomer)) {
            return $newStripeCustomer;
        }

        $id = Arr::get($newStripeCustomer, 'id', false);

        if ($id) {
            $customer->updateMeta($legacyIsValid ? $currencyMetaKey : 'stripe_customer_id', $id);
        }

        return $newStripeCustomer;
    }

    /**
     * The Stripe customer when it still exists, belongs to $customer's email and
     * can bill $currency; null otherwise.
     */
    private static function getUsableStripeCustomer($stripeCustomerId, Customer $customer, $currency = '')
    {
        $stripeCustomer = (new API())->getStripeObject('customers/' . $stripeCustomerId);

        if (is_wp_error($stripeCustomer) || !is_array($stripeCustomer) || empty($stripeCustomer['id'])) {
            return null;
        }

        if (!isset($stripeCustomer['email']) || $stripeCustomer['email'] !== $customer->email) {
            return null;
        }

        return self::stripeCustomerCanBill($stripeCustomer, $currency) ? $stripeCustomer : null;
    }

    // Stripe sets a customer's currency on its first subscription or invoice; empty means never billed.
    private static function stripeCustomerCanBill(array $stripeCustomer, $currency)
    {
        $billedCurrency = strtolower((string)Arr::get($stripeCustomer, 'currency', ''));

        return !$currency || !$billedCurrency || $billedCurrency === strtolower($currency);
    }

    // Stripe takes UGX amounts ×100 like a two-decimal currency, though it charges whole shillings.
    public static function isZeroDecimal($currency)
    {
        return $currency && strtoupper((string)$currency) !== 'UGX' && CurrenciesHelper::isZeroDecimal($currency);
    }

    public static function transformSubscriptionStatus($stripeSubscription, $subscriptionModel = null)
    {
        $status = strtolower($stripeSubscription['status']);

        if ($status === 'active') {
            $status = Status::SUBSCRIPTION_ACTIVE;
        } else if ($status === 'incomplete' || $status === 'incomplete_expired') {
            $status = Status::SUBSCRIPTION_INTENDED;
        } else if ($status === 'trialing') {
            $status = Status::SUBSCRIPTION_TRIALING;
        } else if ($status === 'canceled') {
            $status = Status::SUBSCRIPTION_CANCELED;
            if (Arr::get($stripeSubscription, 'cancellation_details.reason', '') === 'payment_failed') {
                $status = Status::SUBSCRIPTION_EXPIRED;
            }
        } else if ($status === 'unpaid') {
            $status = Status::SUBSCRIPTION_EXPIRED;
        } else if ($status === 'paused') {
            $status = Status::SUBSCRIPTION_PAUSED;
        } else if ($status === 'past_due') {
            $status = Status::SUBSCRIPTION_EXPIRING;
            if ($subscriptionModel && $subscriptionModel->status === 'expired') {
                $status = Status::SUBSCRIPTION_EXPIRED;
            }
        }

        return $status;
    }

    public static function getSubscriptionUpdateData($stripeSubscription, $subscriptionModel = null)
    {
        $stripeStatus = strtolower(Arr::get($stripeSubscription, 'status', ''));

        $status = self::transformSubscriptionStatus($stripeSubscription, $subscriptionModel);

        $amount = Arr::get($stripeSubscription, 'plan.amount', 0);
        $currency = Arr::get($stripeSubscription, 'plan.currency', null);

        if ($currency && self::isZeroDecimal($currency)) {
            $amount = $amount * 100;
        }

        $subscriptionUpdateData = array_filter([
            'current_payment_method' => 'stripe',
            'status'                 => $status,
            'recurring_total'        => $amount,
        ]);

        if ($stripeStatus == Status::SUBSCRIPTION_CANCELED) {
            $cancelledAt = (int)Arr::get($stripeSubscription, 'canceled_at');
            if ($cancelledAt) {
                $subscriptionUpdateData['canceled_at'] = gmdate('Y-m-d H:i:s', $cancelledAt);
            }
        }

        $currentPeriodEnds = (int)Arr::get($stripeSubscription, 'current_period_end');
        // we have to check if the last invoice is paid or not!
        // If not paid, then we have to use the $stripeSubscription['current_period_start']
        $latestInvoice = Arr::get($stripeSubscription, 'latest_invoice', null);
        if ($latestInvoice && !empty($latestInvoice['id'])) {
            // we have the latest invoice
            if (Arr::get($latestInvoice, 'status') !== 'paid') {
                $currentPeriodEnds = (int)Arr::get($stripeSubscription, 'current_period_start');
            }
        }

        if ($currentPeriodEnds) {
            $subscriptionUpdateData['next_billing_date'] = gmdate('Y-m-d H:i:s', $currentPeriodEnds);
        }

        return $subscriptionUpdateData;
    }


    /**
     * Convert a Stripe wire amount (invoice/charge/payment_intent) to FluentCart's
     * internal ×100 unit. Zero-decimal currencies (JPY, KRW, ...) come off the wire
     * unscaled, so they need the ×100 that non-zero-decimal currencies already have.
     *
     * @param int|float $amount
     * @param string $currency
     * @return int
     */
    public static function toInternalAmount($amount, $currency)
    {
        $amount = (int)$amount;

        if ($currency && self::isZeroDecimal($currency)) {
            $amount *= 100;
        }

        return $amount;
    }

    /**
     * Convert a Stripe object's `livemode` flag to a getStripeObject()/getApiKey()
     * `$mode` argument. The store's global mode toggle ('current') is not a reliable
     * proxy for which key an already-fetched object needs — use its own livemode
     * whenever one is available instead of guessing from the store setting.
     *
     * @param bool|null $livemode Null when no prior object/event is available yet.
     * @return string 'live', 'test', or 'current'
     */
    public static function modeFromLivemode($livemode)
    {
        if (is_null($livemode)) {
            return 'current';
        }

        return $livemode ? 'live' : 'test';
    }

    public static function processRemoteRefund($transaction, $amount, $args)
    {
        if (!Money::isWholeAmount($amount, $transaction->currency)) {
            return new \WP_Error('invalid_refund_precision', __('Refund amount must use the smallest unit of the transaction currency.', 'fluent-cart'));
        }

        $intentId = $transaction->vendor_charge_id;
        if (!$intentId) {
            return new \WP_Error('invalid_refund', __('Invalid transaction ID for refund.', 'fluent-cart'));
        }

        $refundAmount = (int)$amount;
        $refundCurrency = $transaction->currency;

        if ($refundCurrency && self::isZeroDecimal($refundCurrency)) {
            $refundAmount = (int)($refundAmount / 100);
        }

        $refundData = [
            'payment_intent' => $intentId,
            'amount'         => $refundAmount,
        ];

        $reason = Arr::get($args, 'reason', '');

        if ($reason && in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'])) {
            $refundData['reason'] = $reason;
        }

        $refunded = (new API())->createStripeObject('refunds', $refundData, $transaction->payment_mode);

        if (is_wp_error($refunded)) {
            return $refunded;
        }

        $status = Arr::get($refunded, 'status');
        $acceptedStatus = ['succeeded', 'pending'];
        if (!in_array($status, $acceptedStatus)) {
            return new \WP_Error('refund_failed', __('Refund could not be processed in stripe. Please check on your stripe account', 'fluent-cart'));
        }

        return Arr::get($refunded, 'id');
    }

    public static function createOrUpdateIpnRefund($refundData, $parentTransaction)
    {
        $allRefunds = OrderTransaction::query()
            ->where('order_id', $refundData['order_id'])
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        if ($allRefunds->isEmpty()) {
            // this is the first refund for this order
            return OrderTransaction::query()->create($refundData);
        }

        $currentRefundTransactionId = Arr::get($refundData, 'meta.parent_id', '');

        $existingLocalRefund = null;
        foreach ($allRefunds as $refund) {
            if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                if ($refund->total != $refundData['total']) {
                    $refund->fill($refundData);
                    $refund->save();
                }
                // this refund already exists
                return $refund;
            }

            if (!$refund->vendor_charge_id) { // this is a local redfund without vendor charge id
                $refundTransactionId = Arr::get($refund->meta, 'parent_id', '');
                $isTransactionMatched = $refundTransactionId == $currentRefundTransactionId;

                // this is a local refund without vendor charge id, we will update it
                if ($refund->total == $refundData['total'] && $isTransactionMatched) {
                    // this refund already exists
                    $existingLocalRefund = $refund;
                }
            }
        }

        if ($existingLocalRefund) {
            $existingLocalRefund->fill($refundData);
            $existingLocalRefund->save();
            return $existingLocalRefund;
        }

        $createdRefund = OrderTransaction::query()->create($refundData);

        PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);
        return $createdRefund;
    }

        /*
     * To validate by session, id
     *
      */
      public static function validateBySession($id)
      {
          $apiKey = (new StripeSettingsBase())->getApiKey();
  
          $session = (new API())->makeRequest('checkout/sessions/' . $id, [], $apiKey, 'GET');
  
          if (!$session || is_wp_error($session)) {
              return null;
          }
  
  
          $order = Order::query()
              ->where('uuid', Arr::get($session, 'client_reference_id'))
              ->first();
          
          if (!$order) {
              return null;
          }
  
          return $order;
  
      }

    public static function getCancelUrl(): string
    {
        $checkoutPage = (new StoreSettings())->getCheckoutPage();
        // get cart hash from url
        $cartHash = App::request()->get('fct_cart_hash', '');
        if ($cartHash) {
            return add_query_arg([
                'fct_cart_hash' => $cartHash
            ], $checkoutPage);
        }
        return $checkoutPage;
    }

}
