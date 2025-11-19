<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php use FluentCart\App\Helpers\Helper;
$showNotice = $show_notice ?? true;


if (isset($heading)): ?>
    <p style="font-size:16px;font-weight:600;color:rgb(44,62,80);margin: 16px 0 0 0;line-height:24px;">
        <?php echo esc_html($heading); ?>
    </p>
<?php endif; ?>

<table role="presentation" style="width: 100%;overflow:hidden;margin-bottom: 0;border-spacing: 0;border:none;padding: 0 0 0 10px;">
    <tbody>
    <tr>
        <td style="border:none;padding: 0;">
            <table role="presentation" style="width: 100%;vertical-align: bottom;border-spacing: 0;padding: 0;margin-bottom: 10px;border:none;">
                <tbody style="width:100%;vertical-align: bottom;">

                <?php foreach ($licenses as $license): ?>
                    <tr style="width:100%;vertical-align: bottom;">
                        <td style="width:75%;border:none;padding: 0;">
                            <span style="font-size: 15px; color: #2F3448; font-weight: 500; overflow: hidden; line-height: 18px; margin-top: 0; margin-bottom: 5px;">
                                <?php  echo esc_html($license->productVariant->variation_title); ?>:
                                <?php echo esc_attr($license->license_key); ?>
                            </span>

                        </td>


                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </td>
    </tr>
    </tbody>
</table>

<?php if($showNotice): ?>
    <table role="presentation"
       style="background-color:rgb(239,246,255);padding:12px;border-radius:6px;margin-bottom:20px;border-width:1px;border-color:rgb(191,219,254)">
    <tbody>
    <tr>
        <td>
            <p style="font-size:14px;font-weight:600;color:rgb(30,58,138);line-height:24px;margin: 0 0 6px 0;">
                Important
            </p>
            <p style="font-size:13px;color:rgb(30,64,175);margin-bottom:0;line-height:1.2;margin-top:0">
                This download link is valid for 7 days. After that, you can download the files again from your account
                on our website.
            </p>
        </td>
    </tr>
    </tbody>
</table>
<?php endif; ?>
