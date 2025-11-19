<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
?>

    <div class="space_bottom_30">
        <p>Hello <?php echo esc_html($order->customer->full_name); ?>,</p>
        <p>Great news! Your order is on its way to you 📦.</p>
    </div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => 'Order Summary',
]);

$downloads = $order->getDownloads();
if($downloads) {
    \FluentCart\App\App::make('view')->render('emails.parts.downloads', [
        'order'         => $order,
        'heading'       => 'Downloads',
        'downloadItems' => $order->getDownloads() ?: [],
    ]);
}

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.addresses', [
    'order' => $order,
]);

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => 'Thank you for choosing us! We hope you’re excited about your order. If you have any questions, feel free to reach out.',
    'link'        => $order->getViewUrl('customer'),
    'button_text' => 'View Details'
]);
