<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="text-align:center;margin-bottom:32px">
    <tbody>
    <tr>
        <td>
            <p style="font-size:14px;color:rgb(75,85,99);margin-bottom:16px;line-height:1.625;margin-top:16px">
                <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </p>
            <a href="<?php echo esc_url($link); ?>" style="display:inline-block;background-color:rgb(17,24,39);color:rgb(255,255,255);padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;text-decoration-line:none" target="_blank">
                <?php echo esc_html($button_text); ?> <?php echo \FluentCart\App\Services\Email\EmailDirection::isRtl() ? '&larr;' : '&rarr;'; ?>
            </a>
        </td>
    </tr>
    </tbody>
</table>
