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
        <p>
            <?php esc_html_e('We have processed a refund for your recent order', 'fluent-cart'); ?>
            <a href="<?php echo esc_url($order->getViewUrl('customer')); ?>">
                #<?php echo esc_html($order->invoice_no); ?>
            </a>.
            <?php echo esc_html__('Thank you for your understanding, and we truly value your trust in us. Below are the details of your refund.', 'fluent-cart'); ?>
        </p>
    </div>
<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Summary', 'fluent-cart'),
]);

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('The refund should appear in your account within 5-10 business days, depending on your payment provider.', 'fluent-cart'),
    'link'        => $order->getViewUrl('customer'),
    'button_text' => __('View Details', 'fluent-cart')
]);
