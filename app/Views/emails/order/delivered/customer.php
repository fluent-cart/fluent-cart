<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
?>

<div class="space_bottom_30">
    <p>
        <?php
            /** translators: %s is the customer name */
            printf(
                    esc_html__( 'Hello %s,', 'fluent-cart' ),
                    esc_html($order->customer->full_name)
            );
        ?>
    </p>

    <p>
        <?php
           echo esc_html__( "Wonderful news! Your order has been successfully delivered. We hope you're thrilled with your purchase! If you need any assistance or have feedback, we're here to help.", 'fluent-cart' )
        ?>
    </p>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Order Summary', 'fluent-cart'),
]);

$downloads = $order->getDownloads();
if($downloads) {
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
    'content'     => __('Thank you for shopping with us! We look forward to serving you again.', 'fluent-cart'),
    'link'        => $order->getViewUrl('customer'),
    'button_text' => __('View Details', 'fluent-cart'),
]);
