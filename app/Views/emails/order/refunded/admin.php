<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
?>

<div class="space_bottom_30">
    <?php if($order->payment_status === \FluentCart\App\Helpers\Status::PAYMENT_REFUNDED): ?>
        <p>Hey Superstar!</p>
        <p><?php echo esc_html($order->customer->full_name); ?> decided to hit the refund button. No worries, though - it's just a tiny hiccup in your retail rockstar journey! Maybe they weren't ready for your awesomeness, but you're still killing it! Check the details below and keep those good vibes going. 😎</p>
    <?php else: ?>
        <p>Hey Shopstar!</p>
        <p>You just tossed out a partial refund like a discount ninja—making customers grin ear to ear! That's how you keep the party poppin'! Peek at the deets and keep slingin' those sweet deals! 🎉</p>
    <?php endif; ?>

    <p style="font-size:16px;font-weight:500;color:rgb(44,62,80);margin:0px;margin-bottom:16px;line-height:24px;margin-top:16px;margin-left:0px;margin-right:0px">Customer's Details:</p>
    <ul>
        <li><strong>Name:</strong> <?php echo esc_html($order->customer->full_name); ?></li>
        <li><strong>Email:</strong> <?php echo esc_html($order->customer->email); ?></li>
    </ul>

</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Summary', 'fluent-cart'),
    'is_refund'      => true,
]);


echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('View the details of this refund on the order details page.', 'fluent-cart'),
    'link'        => $order->getViewUrl('customer'),
    'button_text' => __('View Details', 'fluent-cart')
]);
