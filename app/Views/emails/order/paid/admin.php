<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
$celebration = \FluentCart\App\Services\TemplateService::getCelebration('order');
?>

<?php
if (!empty($celebration)) {
    \FluentCart\App\App::make('view')->render('emails.parts.celebration', [
        'text' => $celebration
    ]);
}
?>

<div class="space_bottom_30">
    <p><?php echo esc_html__('Hey there 🙌,', 'fluent-cart'); ?></p>
    <p>
        <?php
        printf(
            /* translators: %s is the customer's full name */
            esc_html__('%s just placed an order. Here are the details:', 'fluent-cart'),
            esc_html($order->customer->full_name)
        );
        ?>
    </p>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Order Summary', 'fluent-cart'),
]);

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.addresses', [
    'order' => $order,
]);

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('To view more details of this order, please check the order detail page.', 'fluent-cart'),
    'link'        => $order->getViewUrl('admin'),
    'button_text' => __('View Details', 'fluent-cart')
]);
