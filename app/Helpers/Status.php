<?php

namespace FluentCart\App\Helpers;

class Status
{
    // product statuses: publish, draft, pending, private, future
    public const PRODUCT_PUBLISH = 'publish';
    public const PRODUCT_DRAFT = 'draft';
    public const PRODUCT_PRIVATE = 'private';
    public const PRODUCT_FUTURE = 'future';
    public const PRODUCT_TRASH = 'trash';

    // Order Statuses
    public const ORDER_PROCESSING = 'processing';
    public const ORDER_COMPLETED = 'completed';
    public const ORDER_ON_HOLD = 'on-hold';
    public const ORDER_CANCELED = 'canceled';
    public const ORDER_FAILED = 'failed';

    // Payment Statuses
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_PARTIALLY_PAID = 'partially_paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_PARTIALLY_REFUNDED = 'partially_refunded';
    public const PAYMENT_AUTHORIZED = 'authorized';

    // Transaction Statuses
    public const TRANSACTION_SUCCEEDED = 'succeeded';
    public const TRANSACTION_AUTHORIZED = 'authorized';
    public const TRANSACTION_PENDING = 'pending';
    public const TRANSACTION_REFUNDED = 'refunded';
    public const TRANSACTION_FAILED = 'failed';
    public const TRANSACTION_DISPUTE_LOST = 'dispute_lost';


    // Transaction table transaction_type status
    public const TRANSACTION_TYPE_CHARGE = 'charge';
    public const TRANSACTION_TYPE_REFUND = 'refund';
    public const TRANSACTION_TYPE_DISPUTE = 'dispute';

    // todo: will remove after some necessary test
    public const TRANSACTION_TYPE_SIGNUP = 'signup_fee';

    // Subscription Statuses
    public const SUBSCRIPTION_PENDING = 'pending';
    public const SUBSCRIPTION_INTENDED = 'intended';
    public const SUBSCRIPTION_TRIALING = 'trialing';
    public const SUBSCRIPTION_ACTIVE = 'active';

    public const SUBSCRIPTION_CANCELED = 'canceled';
    public const SUBSCRIPTION_PAUSED = 'paused';
    public const SUBSCRIPTION_PAST_DUE = 'past_due';
    public const SUBSCRIPTION_EXPIRED = 'expired';
    public const SUBSCRIPTION_FAILING = 'failing';
    public const SUBSCRIPTION_EXPIRING = 'expiring';
    public const SUBSCRIPTION_COMPLETED = 'completed';

    // billing interval
    public const BILLING_YEARLY = 'yearly';
    public const BILLING_HALF_YEARLY = 'half_yearly';
    public const BILLING_QUARTERLY = 'quarterly';
    public const BILLING_MONTHLY = 'monthly';
    public const BILLING_WEEKLY = 'weekly';
    public const BILLING_DAILY = 'daily';

    // Shipping Statuses
    public const SHIPPING_UNSHIPPED = 'unshipped';
    public const SHIPPING_SHIPPED = 'shipped';
    public const SHIPPING_DELIVERED = 'delivered';
    public const SHIPPING_UNSHIPPABLE = 'unshippable';

    // Customer Statuses
    public const CUSTOMER_ACTIVE = 'active';
    public const CUSTOMER_INACTIVE = 'inactive';

    // Stock Statuses
    public const STOCK_INSTOCK = 'instock';
    public const STOCK_OUTOFSTOCK = 'outofstock';
    public const STOCK_ONBACKORDER = 'onbackorder';


    public const SCHEDULE_PENDING = 'pending';
    public const SCHEDULE_COMPLETED = 'completed';
    public const SCHEDULE_FAILED = 'failed';

    public const SCHEDULE_PROCESSING = 'processing';

    public const LICENSE_ACTIVE = 'active';
    public const LICENSE_DISABLED = 'disabled';
    public const LICENSE_EXPIRED = 'expired';

    public const FULFILLMENT_TYPE_PHYSICAL = 'physical';
    public const FULFILLMENT_TYPE_DIGITAL = 'digital';

    public const ORDER_TYPE_PAYMENT = 'payment';
    public const ORDER_TYPE_SUBSCRIPTION = 'subscription';
    public const ORDER_TYPE_RENEWAL = 'renewal';


    public static function getProductStatuses($withLabel = true)
    {
        return apply_filters('fluent_cart/product_statuses', [
            self::PRODUCT_PUBLISH => __('Publish', 'fluent-cart'),
            self::PRODUCT_DRAFT   => __('Draft', 'fluent-cart'),
            self::PRODUCT_PRIVATE => __('Private', 'fluent-cart'),
            self::PRODUCT_FUTURE  => __('Scheduled', 'fluent-cart'),
            self::PRODUCT_TRASH   => __('Trashed', 'fluent-cart'),
        ], []);

        if ($withLabel) {
            return $statues;
        }

        return array_keys($statues);
    }

    public static function getScheduleStatus($status): string
    {
        $statuses = [
            'pending'    => Status::SCHEDULE_PENDING,
            'processing' => Status::SCHEDULE_PROCESSING,
            'completed'  => Status::SCHEDULE_COMPLETED,
            'failed'     => Status::SCHEDULE_FAILED,
        ];

        return $statuses[$status] ?? Status::SCHEDULE_PENDING;
    }

    public static function getLicenseStatus($status): string
    {
        $statuses = [
            'active'   => Status::LICENSE_ACTIVE,
            'disabled' => Status::LICENSE_DISABLED,
            'expired'  => Status::LICENSE_EXPIRED,
        ];

        return $statuses[$status] ?? Status::LICENSE_ACTIVE;
    }

    public static function productAdminAllStatuses()
    {
        $statuses = self::getProductStatuses();
        unset($statuses[self::PRODUCT_TRASH]);
        return array_keys($statuses);
    }


    // Order statuses
    public static function getOrderStatuses()
    {
        return apply_filters('fluent_cart/order_statuses', [
            self::ORDER_PROCESSING => __('Processing', 'fluent-cart'),
            self::ORDER_COMPLETED  => __('Completed', 'fluent-cart'),
            self::ORDER_ON_HOLD    => __('On Hold', 'fluent-cart'),
            self::ORDER_CANCELED   => __('Canceled', 'fluent-cart'),
            self::ORDER_FAILED     => __('Failed', 'fluent-cart'),
        ], []);
    }

    public static function getEditableOrderStatuses()
    {
        return apply_filters('fluent-cart/editable_order_statuses', [
            self::ORDER_ON_HOLD    => __('On Hold', 'fluent-cart'),
            self::ORDER_PROCESSING => __('Processing', 'fluent-cart'),
            self::ORDER_COMPLETED  => __('Completed', 'fluent-cart'),
            //  'archived' => __('Archived', 'fluent-cart'),
            self::ORDER_CANCELED   => __('Canceled', 'fluent-cart')
        ], []);
    }


    // Payment statuses
    public static function getPaymentStatuses()
    {
        return apply_filters('fluent_cart/payment_statuses', [
            self::PAYMENT_PENDING            => __('Pending', 'fluent-cart'),
            self::PAYMENT_PAID               => __('Paid', 'fluent-cart'),
            self::PAYMENT_PARTIALLY_PAID     => __('Partially Paid', 'fluent-cart'),
            self::PAYMENT_FAILED             => __('Failed', 'fluent-cart'),
            self::PAYMENT_REFUNDED           => __('Refunded', 'fluent-cart'),
            self::PAYMENT_PARTIALLY_REFUNDED => __('Partially Refunded', 'fluent-cart'),
            self::PAYMENT_AUTHORIZED         => __('Authorized', 'fluent-cart'),
        ], []);
    }

    // Transaction statuses
    public static function getTransactionStatuses($withLabel = true)
    {
        return apply_filters('fluent_cart/transaction_statuses', [
            self::TRANSACTION_PENDING   => __('Pending', 'fluent-cart'),
            self::TRANSACTION_SUCCEEDED => __('Succeeded', 'fluent-cart'),
            self::TRANSACTION_AUTHORIZED => __('Authorized', 'fluent-cart'),
            self::TRANSACTION_FAILED    => __('Failed', 'fluent-cart'),
            self::TRANSACTION_REFUNDED  => __('Refunded', 'fluent-cart'),
        ], []);

        if ($withLabel) {
            return $statuses;
        }

        return array_keys($statuses);
    }

    public static function getEditableTransactionStatuses($withLabel = true)
    {
        $statuses = apply_filters('fluent-cart/editable_transaction_statuses', [
            self::TRANSACTION_PENDING   => __('Pending', 'fluent-cart'),
            self::TRANSACTION_SUCCEEDED => __('Succeeded', 'fluent-cart'),
            self::TRANSACTION_AUTHORIZED => __('Authorized', 'fluent-cart'),
            self::TRANSACTION_FAILED    => __('Failed', 'fluent-cart'),
            self::TRANSACTION_REFUNDED  => __('Refunded', 'fluent-cart'),
        ], []);

        if ($withLabel) {
            return $statuses;
        }

        return array_keys($statuses);
    }

    // Shipping statuses
    public static function getShippingStatuses()
    {
        return apply_filters('fluent_cart/shipping_statuses', [
            self::SHIPPING_UNSHIPPED   => __('Unshipped', 'fluent-cart'),
            self::SHIPPING_SHIPPED     => __('Shipped', 'fluent-cart'),
            self::SHIPPING_DELIVERED   => __('Delivered', 'fluent-cart'),
            self::SHIPPING_UNSHIPPABLE => __('Unshippable', 'fluent-cart'),
        ], []);
    }

    public static function getEditableShippingStatuses()
    {
        return apply_filters('fluent-cart/editable_order_statuses', [
            self::SHIPPING_UNSHIPPED   => __('Unshipped', 'fluent-cart'),
            self::SHIPPING_SHIPPED     => __('Shipped', 'fluent-cart'),
            self::SHIPPING_DELIVERED   => __('Delivered', 'fluent-cart'),
            self::SHIPPING_UNSHIPPABLE => __('Unshippable', 'fluent-cart'),
        ], []);
    }

    // Subscription statuses
    public static function getSubscriptionStatuses()
    {
        return apply_filters('fluent_cart/subscription_statuses', [
            self::SUBSCRIPTION_PENDING  => __('Pending', 'fluent-cart'),
            self::SUBSCRIPTION_ACTIVE   => __('Active', 'fluent-cart'),
            self::SUBSCRIPTION_FAILING  => __('Failing', 'fluent-cart'),
            self::SUBSCRIPTION_PAUSED   => __('Paused', 'fluent-cart'),
            self::SUBSCRIPTION_EXPIRED  => __('Expired', 'fluent-cart'),
            self::SUBSCRIPTION_EXPIRING => __('Expiring', 'fluent-cart'),
            self::SUBSCRIPTION_CANCELED => __('Canceled', 'fluent-cart'),
            self::SUBSCRIPTION_TRIALING => __('Trialing', 'fluent-cart'),
            self::SUBSCRIPTION_INTENDED => __('Intended', 'fluent-cart'),
            self::SUBSCRIPTION_PAST_DUE => __('Past Due', 'fluent-cart'),
            self::SUBSCRIPTION_COMPLETED   => __('Completed', 'fluent-cart'),
        ], []);
    }

    // Get Validable Subscription Statuses
    public static function getValidableSubscriptionStatuses()
    {
        return apply_filters('fluent_cart/validable_subscription_statuses', [
            self::SUBSCRIPTION_ACTIVE,
            self::SUBSCRIPTION_TRIALING,
        ], []);
    }


    // Stock statuses
    public static function getStockStatuses($withLabel = true)
    {
        $statues = [
            self::STOCK_INSTOCK    => __('In Stock', 'fluent-cart'),
            self::STOCK_OUTOFSTOCK => __('Out Of Stock', 'fluent-cart'),
        ];

        if ($withLabel) {
            return $statues;
        }

        return array_keys($statues);
    }

    public static function getOrderSuccessStatuses()
    {
        return [
            self::ORDER_COMPLETED,
            self::ORDER_PROCESSING,
        ];
    }

    public static function getOrderFailedStatuses()
    {
        return [
            self::ORDER_FAILED,
            //'refunded',
            self::ORDER_CANCELED,
        ];
    }

    public static function getOrderPaymentSuccessStatuses()
    {
        return [
            self::PAYMENT_PAID,
            self::PAYMENT_PARTIALLY_REFUNDED,
            self::PAYMENT_PARTIALLY_PAID
        ];
    }

    public static function getReportStatuses()
    {
        return [
            self::PAYMENT_PAID,
            self::PAYMENT_REFUNDED,
            self::PAYMENT_PARTIALLY_PAID,
            self::PAYMENT_PARTIALLY_REFUNDED,
        ];
    }

    public static function getTransactionSuccessStatuses()
    {
        return apply_filters('fluent_cart/transaction_success_statuses', [self::TRANSACTION_SUCCEEDED, self::TRANSACTION_AUTHORIZED], []);
    }

    // Get all statuses (optional utility)
    public static function all()
    {
        return [
            'product'      => self::getProductStatuses(),
            'order'        => self::getOrderStatuses(),
            'payment'      => self::getPaymentStatuses(),
            'transaction'  => self::getTransactionStatuses(),
            'shipping'     => self::getShippingStatuses(),
            'customer'     => self::getEditableCustomerStatuses(),
            'subscription' => self::getSubscriptionStatuses(),
        ];
    }

    public static function getEditableCustomerStatuses()
    {
        return apply_filters('fluent-cart/editable_customer_statuses', [
            self::CUSTOMER_ACTIVE   => __('Active', 'fluent-cart'),
            self::CUSTOMER_INACTIVE => __('Inactive', 'fluent-cart'),
        ], []);
    }

    // call when syncing status from external services, ex: 
    public static function syncPaymentStatus($status)
    {
        $status = strtolower($status);
        $statuses = self::getPaymentStatuses();

        if (isset($statuses[$status])) {
            return $status;
        }

        if ('completed' === $status) {
            return self::PAYMENT_PAID;
        }

        if ('succeeded' === $status) {
            return self::PAYMENT_PAID;
        }

        return $status;
    }

    public static function syncTransactionStatus($status)
    {
        $status = strtolower($status);
        $statuses = self::getTransactionStatuses();

        if (isset($statuses[$status])) {
            return $status;
        }

        if ('paid' === $status) {
            return self::TRANSACTION_SUCCEEDED;
        } else if ('succeeded' === $status) {
            return self::TRANSACTION_SUCCEEDED;
        }

        return $status;
    }

    public static function syncSubscriptionStatus($status)
    {
        if ('cancelled' === $status || 'canceled' === $status) {
            return self::SUBSCRIPTION_CANCELED;
        }

        return $status;
    }

    public static function eventTriggers(): array
    {
        return [
            'require_list'   => false,
            'required'       => true,
            'key'            => 'event_trigger',
            'options'        => [
                'order'        => [
                    'label'   => __('Orders', 'fluent-cart'),
                    'options' => [
                        [
                            'value' => 'order_paid_done',
                            'label' => __('Order Paid (Payment / Subscription)', 'fluent-cart')
                        ],
                        [
                            'value' => 'order_status_changed_to_canceled',
                            'label' => __('Order Canceled', 'fluent-cart')
                        ],
                        [
                            'value' => 'order_fully_refunded',
                            'label' => __('Order Refunded (Full)', 'fluent-cart')
                        ]
                    ]
                ],
                'subscription' => [
                    'label'   => __('Subscriptions', 'fluent-cart'),
                    'options' => [
                        [
                            'value' => 'subscription_activated',
                            'label' => __('Subscription Activated', 'fluent-cart')
                        ],
                        [
                            'value' => 'subscription_canceled',
                            'label' => __('Subscription Canceled', 'fluent-cart')
                        ],
                        [
                            'value' => 'subscription_renewed',
                            'label' => __('Subscription Renewed', 'fluent-cart')
                        ],
                        [
                            'value' => 'subscription_eot',
                            'label' => __('Subscription End of Term (Completed)', 'fluent-cart')
                        ],
                        [
                            'value' => 'subscription_expired_validity',
                            'label' => __('Subscription Expired / End of Access Validity', 'fluent-cart')
                        ]
                    ]
                ],
                'product'      => [
                    'label'   => __('Shipping', 'fluent-cart'),
                    'options' => [
                        [
                            'value' => 'shipping_status_changed_to_shipped',
                            'label' => __('Order Shipped', 'fluent-cart')
                        ],
                        [
                            'value' => 'shipping_status_changed_to_delivered',
                            'label' => __('Order Delivered', 'fluent-cart')
                        ]
                    ]
                ]
            ],
            'inline_tip'     => __('Select in which event you want to trigger this feed', 'fluent-cart'),
            'label'          => __('Event Trigger', 'fluent-cart'),
            'placeholder'    => __('Select Event', 'fluent-cart'),
            'component'      => 'selection_group',
            'checkbox_label' => __('Event Trigger For This Feed', 'fluent-cart'),
        ];
    }

    // Additional statuses can be added here as needed
}
