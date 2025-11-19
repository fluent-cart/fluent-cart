<?php
defined('ABSPATH') || exit;
?>
<div class="fluent_cart_order_confirmation">
    <div class="fluent_cart_pdf_content">
        <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>
</div>

<div style="display: flex; justify-content: center; padding:20px;">
    <button style="background: var(--fluent-cart-primary-color, #253241); color: #fff; padding: 7px 20px; border-radius: 8px; border: none; font-size: 16px; font-weight: 500; min-height: 40px;" id="print-button">
        <?php echo esc_html__('Print', 'fluent-cart'); ?>
    </button>
</div>
