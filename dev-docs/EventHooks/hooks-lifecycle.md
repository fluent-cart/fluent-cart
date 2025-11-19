# Hooks Documentation

Each hooks group has multiple hooks with chronological order. When a hook group is called, it will call every step by logic mentioned on that single hook.

## Table of Contents

- [Confirm Payment Succeeded Hooks Group](#confirm-payment-succeeded-hooks-group)
- [Subscription Synced Hooks Group](#subscription-synced-hooks-group)
- [Payment Confirmations](#payment-confirmations)
- [Subscription Cancellation](#subscription-cancellation)
- [Refund](#refund)
- [Order Creation](#order-creation)
- [Admin Order Update](#admin-order-update)
- [Order Deleted](#order-deleted)

## Confirm Payment Succeeded Hooks Group

1. **Always called**
   - Code: `(new OrderPaid($this->order, $this->order->customer, $latestTransaction))->dispatch();`
   - Hook: `fluent_cart/order_paid`
   - Data:
     ```PHP
     // model instance
     [
       "order", "customer", "transaction"
     ]
     ```

2. **Always called, if Order status changed**
   - Code: `(new OrderStatusUpdated($this->order, $oldOrderStatus, $this->order->status, true, $actionActivity, 'order_status'))->dispatch();`
   - Hook: `fluent_cart/order_status_updated`
   - Data:
     ```PHP
     // data you will get from that event
     [
       "order", "old_status", "new_status", 'manage_stock', 'activity'
     ]
     ```

3. **Called only, if digital product and has not refunded and status is not Status::ORDER_COMPLETED**
   - Code: `(new OrderStatusUpdated($this->order, $oldOrderStatus, $this->order->status, true, $actionActivity, 'order_status'))->dispatch();`
   - Hook: `fluent_cart/order_status_updated`
   - Data:
     ```PHP
      // data you will get from that event
      [
        "order", "old_status", "new_status", 'manage_stock', 'activity'
      ]
     ```

## Subscription Synced Hooks Group

1. **Called only, if EOT ($billTimes > 0 && $billsCount >= $billTimes)**
   - Code: `(new SubscriptionEOT($subscriptionModel, $subscriptionModel->order))->dispatch();`
   - Hook: `fluent_cart/subscription_eot`
   - Data:
     ```PHP
     // model instance
     [
       "subscription", "order", "customer"
     ]
     ```

2. **Always called**
   - Code:
     ```php
     do_action('fluent_cart/payments/subscription_status_changed', [
         'subscription' => $subscriptionModel,
         'order'        => $subscriptionModel->order,
         'customer'     => $subscriptionModel->customer,
         'old_status'   => $oldStatus,
         'new_status'   => $subscriptionModel->status
     ]);
     ```

3. **Always called**
   - Code:
     ```php
     /* fluent_cart/payments/subscription_cancelled
      * fluent_cart/payments/subscription_active
      * fluent_cart/payments/subscription_paused
      * fluent_cart/payments/subscription_expired
      * fluent_cart/payments/subscription_failing
      * fluent_cart/payments/subscription_expiring
      * fluent_cart/payments/subscription_completed
      */
     do_action('fluent_cart/payments/subscription_' . $subscriptionModel->status, [
         'subscription' => $subscriptionModel,
         'order'        => $subscriptionModel->order,
         'customer'     => $subscriptionModel->customer,
         'old_status'   => $oldStatus,
         'new_status'   => $subscriptionModel->status
     ]);
     ```

4. **Always called**
   - Code: `(new SubscriptionRenewed($subscriptionModel, $renewalOrder, $subscriptionModel->order, $renewalOrder->customer))->dispatch();`
   - Hook: `fluent_cart/subscription_renewed`
   - Data:
     ```PHP
     // model instance
     [
       "subscription", "order", "main_order", "customer"
     ]
     ```

## Payment Confirmations

### ConfirmPayment First time (Stripe & PayPal)

1. **If (SUBSCRIPTION) && Status::SUBSCRIPTION_ACTIVE or Status::SUBSCRIPTION_TRIALING**
   - Code: `(new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();`
   - Hook: `fluent_cart/subscription_activated`
   - Data:
     ```PHP
     // model instance
     [
       "subscription", "order", "customer", "meta"
     ]
     ```

   - [Confirm Payment Succeeded Hooks Group](#confirm-payment-succeeded-hooks-group)

### Renewal (Manual / IPN for Stripe & PayPal)

   - [Subscription Synced Hooks Group](#subscription-synced-hooks-group)

### IPN Stripe Onetime / Subscription

   - [Confirm Payment Succeeded Hooks Group](#confirm-payment-succeeded-hooks-group)

### IPN PayPal Onetime / Subscription

   - [Confirm Payment Succeeded Hooks Group](#confirm-payment-succeeded-hooks-group)

## Subscription Cancellation (Stripe & PayPal)

### Manual

1. **Fire hooks == 'yes' and not Status::SUBSCRIPTION_COMPLETED**
   - Code: `(new SubscriptionCanceled($this, $this->order, $this->order->customer, $note))->dispatch();`
   - Hook: `fluent_cart/subscription_canceled`
   - Data:
     ```PHP
     [
       "subscription", "order", "customer", // models
        "reason" // reason of cancellation string
     ]
     ```

### Cancellation IPN (Stripe & PayPal)

1. **Stripe**
    - [Subscription Synced Hooks Group](#subscription-synced-hooks-group)

#### Note
- Issue: `fluent_cart/payments/subscription_canceled` hook called from the **SUBSCRIPTION SYNCED** process
- But the manual cancellation fired the `new SubscriptionCanceled()` event which has different hook

## Refund

1. **Always called**
   - Code: `(new OrderRefund($order, $refundTransaction, Arr::get($args, 'item_ids'), $manageStock))->dispatch();`
   - Hook: `fluent_cart/order_refunded`
   - Data:
     ```PHP
     [
       "order", "manage_stock", "transaction", "customer",  "refunded_item_ids", "refunded_amount", "type"
     ]
     ```

2. **Called only, if full refunded**
   - Code: `do_action('fluent_cart/order_fully_refunded', $data);`
   - Data:
     ```PHP
     [
          'order','refunded_items','refunded_amount','manage_stock','transaction','customer','type'            
     ]
     ```

3. **Called only, if partial refunded**
   - Code: `do_action('fluent_cart/order_partially_refunded', $data);`
   - Data:
     ```PHP
     [
          'order','refunded_items','refunded_amount','manage_stock','transaction','customer','type'            
     ]
     ```

4. **Called only, if subscription canceled on refund**
   - Code: `(new SubscriptionCanceled($this, $this->order, $this->order->customer, $note))->dispatch();`
   - Hook: `fluent_cart/subscription_canceled`
   - Data:
     ```PHP
     // model instance
     [
            'subscription','order','customer', 'reason',
     ]
     ```


## Order Creation

1. **Always called**
   - Code: `(new OrderCreated($order))->dispatch();`
   - Hook: `fluent_cart/order_created`
   - Data:
     ```PHP
     // model instance
     [
       "order", "customer", "transaction"
     ]
     ```

## Admin Order Update

1. **Always called**
   - Code: `(new OrderUpdated($order))->dispatch();`
   - Hook: `fluent_cart/order_updated`
   - Data:
   ```
    // model instance
     [
        'order', 'old_order'
     ]
     ```