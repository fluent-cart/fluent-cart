<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="fct-admin-product-header">
    <div>
        <div class="fct-admin-product-info">
            <a href="<?php echo esc_url($products_url ??''); ?>" class="back-arrow">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="10" viewBox="0 0 16 10" fill="none">
                    <path d="M2.1665 5L14.6665 4.9998" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M5.49992 0.833008L2.04036 4.29257C1.70703 4.6259 1.54036 4.79257 1.54036 4.99967C1.54036 5.20678 1.70703 5.37345 2.04036 5.70678L5.49992 9.16634" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <span class="product-name"><?php echo esc_html($product_name); ?></span>
            <span class="product-id"> #<?php echo esc_html($product_id); ?></span>
            <span class="badge <?php echo (esc_attr($status) == 'draft') ? 'info' : ((esc_attr($status) == 'publish') ? 'success' : ((esc_attr($status) == 'future') ? 'warning' : '')); ?>">
            <?php echo esc_html($status); ?>
        </span>
        </div>

        <ul class="fct-admin-product-menu">
            <?php foreach ($menu_items as $itemSlug => $menu_item): ?>
                <li class="fct_menu_<?php echo esc_attr($itemSlug); ?> <?php echo ($active == $itemSlug) ? 'active-menu' : ''; ?>">
                    <a href="<?php echo esc_url($menu_item['link']); ?>">
                        <?php echo esc_attr($menu_item['label']); ?>
                        <?php echo esc_html($active === $itemSlug); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div id="fct-admin-product-header-buttons"></div>
</div>
