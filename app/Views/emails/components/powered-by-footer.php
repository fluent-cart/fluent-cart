<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php use FluentCart\App\App;

if (!App::isProActive()) : ?>
<div style="background: #fff;padding: 32px; text-align: center; font-size: 16px; color: #2F3448;">Global Footer - <?php echo esc_html__('Powered by ', 'fluent-cart')  ?> <a href="https://fluentcart.com" style="color: #017EF3; text-decoration: none;"><?php echo esc_html__('FluentCart', 'fluent-cart')?></a>
</div>
<?php endif; ?>
