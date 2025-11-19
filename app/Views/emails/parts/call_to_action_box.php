<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="text-align:center;margin-bottom:32px">
    <tbody>
    <tr>
        <td>
            <p style="font-size:15px;color:rgb(55,65,81);margin-bottom:16px;line-height:1.625;margin-top:16px">
                <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </p>
            <a href="<?php echo esc_url($link); ?>" style="display:inline-block;background-color:rgb(15,23,42);color:rgb(255,255,255);padding-left:24px;padding-right:24px;padding-top:12px;padding-bottom:12px;border-radius:6px;font-size:14px;font-weight:600;text-decoration-line:none" target="_blank">
                <?php echo esc_html($button_text); ?>
            </a>
        </td>
    </tr>
    </tbody>
</table>
