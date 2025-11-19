<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
    use FluentCart\App\Helpers\Helper;
?>

<tr>
    <td>
        <div style="overflow: hidden;">
            <div style="float: left; border: 1px solid #D6DAE1; width: 32px; height: 32px; border-radius: 4px; margin-right: 12px; margin-top: 3px">
                <img src="<?php echo esc_url($product->thumbnail ?? ''); ?>" style="width: 100%; height: 100%; border-radius: 3px;"  alt="<?php echo esc_attr($item->post_title); ?>"/>
            </div>
            <div style="overflow: hidden;">
                <div style="font-size: 13px; color: #2F3448; font-weight: 500; overflow: hidden; line-height: 18px; margin-top: 0; margin-bottom: 5px">
                    <?php echo esc_html($item->post_title); ?>
                </div>
                <div style="margin: 0; font-size: 12px; color: #758195; font-weight: 400; line-height: 15px;">
                    -- <?php echo esc_html($item->title); ?>
                </div>
            </div>
        </div>
    </td>
    <td style="text-align: center; padding-left: 8px; padding-right: 8px;"><?php echo (int) $item->quantity; ?></td>
    <td style="text-align: center; padding-left: 8px; padding-right: 8px;"><?php echo esc_html(Helper::toDecimal($item->unit_price)); ?></td>
    <td style="text-align: center; padding-left: 8px; padding-right: 8px;"><?php echo esc_html(Helper::toDecimal($item->subtotal)); ?></td>
</tr>
