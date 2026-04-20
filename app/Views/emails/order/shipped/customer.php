<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
?>

    <div class="space_bottom_30">
        <p>
            <?php
                printf(
                    /** translators: %s is the customer name */
                    esc_html__( 'Hello %s,', 'fluent-cart' ),
                    esc_html( $order->customer->full_name )
                );
            ?>
        </p>
        <p>
            <?php echo esc_html__( 'Great news! Your order is on its way to you 📦.', 'fluent-cart' ); ?>
        </p>
    </div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => esc_html__('Order Summary', 'fluent-cart'),
]);

$downloads = $order->getDownloads();
if($downloads) {
    \FluentCart\App\App::make('view')->render('emails.parts.downloads', [
        'order'         => $order,
        'heading'       => esc_html__('Downloads', 'fluent-cart'),
        'downloadItems' => $downloads,
    ]);
}

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.addresses', [
    'order' => $order,
]);

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => esc_html__('Thank you for choosing us! We hope you’re excited about your order. If you have any questions, feel free to reach out.', 'fluent-cart'),
    'link'        => $order->getViewUrl('customer'),
    'button_text' => esc_html__('View Details', 'fluent-cart'),
]);
