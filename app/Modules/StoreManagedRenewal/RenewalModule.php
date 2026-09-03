<?php

namespace FluentCart\App\Modules\StoreManagedRenewal;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\Framework\Support\Arr;

class RenewalModule
{
    public static function register()
    {
        $self = new static();
        App::getInstance()->addAction('fluentcart_loaded', [$self, 'init']);
    }

    public function init($app)
    {
        // Register the scheduler for processing manual subscription renewals
        (new Services\RenewalScheduler())->register();

        // Handle renewal invoice paid — fired from StatusHelper::syncOrderStatuses() whenever
        // a renewal order transitions to paid. Covers all payment paths: admin mark-as-paid,
        // offline gateway, Stripe/Paystack/MercadoPago webhooks — they all go through syncOrderStatuses.
        add_action('fluent_cart/renewal_paid', function ($data) {
            RenewalService::handleRenewalPaid(['order' => $data['order'] ?? null]);
        }, 10, 1);

        // Fired by gateways when a renewal invoice's charge is deferred (e.g. Stripe setup intent).
        // Marks the invoice as payment_scheduled so the customer and admin know payment is coming.
        $markRenewalInvoicePaymentScheduled = function ($payload, $legacySubscription = null) {
            $order = is_array($payload) ? Arr::get($payload, 'order') : $payload;

            if (!$order) {
                return;
            }

            if ($order->payment_status === Status::PAYMENT_PENDING) {
                $order->payment_status = Status::PAYMENT_SCHEDULED;
                $order->save();
            }
        };
        
        add_action('fluent_cart/renewal/payment_scheduled', $markRenewalInvoicePaymentScheduled, 10, 1);
    }
}
