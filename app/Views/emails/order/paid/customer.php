<?php if (!defined('ABSPATH')) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
?>

    <div class="space_bottom_30">
        <p><?php
            printf(
                /* translators: %s is the customer's full name */
                esc_html__('Hello %s,', 'fluent-cart'),
                esc_html($order->customer->full_name)
            );
            ?></p>
        <p><?php esc_html_e('Thank you for purchase! Your order has been successfully placed and confirmed. Here is the details of your order.', 'fluent-cart'); ?></p>
    </div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Order Summary', 'fluent-cart'),
]);


if ($order->subscriptions && $order->subscriptions->count() > 0) {
    \FluentCart\App\App::make('view')->render('invoice.parts.subscription_items', [
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
    'button_text' => 'View Details'
]);
