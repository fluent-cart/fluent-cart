<?php if (!defined('ABSPATH')) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
?>

    <div class="space_bottom_30">
        <p class="fct-email-greeting"><?php
            printf(
                /* translators: %s is the customer's full name */
                esc_html__('Hello %s,', 'fluent-cart'),
                esc_html($order->customer->full_name)
            );
            ?></p>
        <p><?php esc_html_e('Thank you for your purchase! Your order has been successfully placed and confirmed. Here are your order details.', 'fluent-cart'); ?></p>
    </div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Order Summary', 'fluent-cart'),
]);


if ($order->subscriptions && $order->subscriptions->count() > 0) {
    \FluentCart\App\App::make('view')->render('emails.parts.subscription_summary', [
        'subscriptions' => $order->subscriptions,
        'order'         => $order
    ]);
}

$licenses = $order->getLicenses();
if ($licenses && $licenses->count() > 0) {
    \FluentCart\App\App::make('view')->render('emails.parts.licenses', [
        'licenses'    => $licenses,
        'heading'     => __('Licenses', 'fluent-cart'),
        'show_notice' => false
    ]);
}

// The admin preview renders this with a stub order whose id may match a real order.
if ($order instanceof \FluentCart\App\Models\Order) {
    /**
     * Print what the order grants beyond licenses (memberships, course access, ...).
     * Output is echoed inside the email body, so listeners escape their own HTML.
     *
     * @param array $data order (Order), context (string: 'order_paid_customer' | 'payment_receipt')
     */
    do_action('fluent_cart/email/order_entitlements', [
        'order'   => $order,
        'context' => 'order_paid_customer',
    ]);
}

$downloads = $order->getDownloads();

if ($downloads) {
    \FluentCart\App\App::make('view')->render('emails.parts.downloads', [
        'order'         => $order,
        'heading'       => __('Downloads', 'fluent-cart'),
        'downloadItems' => $downloads,
    ]);
}

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.addresses', [
    'order' => $order,
]);

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('To download receipt and view your order details, please visit the order details page.', 'fluent-cart'),
    'link'        => $order->getViewUrl('customer'),
    'button_text' => __('View Details', 'fluent-cart'),
]);
