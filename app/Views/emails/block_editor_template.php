<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<html dir="ltr" lang="en">
<head>
    <meta content="text/html; charset=UTF-8" http-equiv="Content-Type">
    <meta name="x-apple-disable-message-reformatting">
    <style>
        html {
            background-color: rgb(248, 249, 250);
            font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
        }
        body {
            background-color: rgb(248, 249, 250);
            padding-top: 40px;
            padding-bottom: 40px;
            font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
        }

        p {
            font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
            font-size: 14px;
            margin: 0px;
            margin-bottom: 8px;
            line-height: 24px;
        }

        .email_footer p {
            font-size: 12px;
            color: rgb(127, 140, 141);
            margin: 0px;
            line-height: 24px;
            margin-bottom: 8px;
        }

        hr {
            border-color: rgb(229, 231, 235);
            margin-bottom: 20px;
            width: 100%;
            border: none;
            border-top: 1px solid #eaeaea;
        }

        .space_bottom_30 {
            display: block;
            width: 100%;
            margin-bottom: 30px;
        }
        .fct_order_wrapper {
            margin-bottom: 20px;
            background-color:rgb(255,255,255);margin-left:auto;margin-right:auto;padding-left:32px;padding-right:32px;padding-top:32px;padding-bottom:32px;max-width:620px;
        }
        .fct_root_group {
            margin-bottom: 20px;
            background-color:rgb(255,255,255);
            margin-left:auto;
            margin-right:auto;
            padding-left:32px;padding-right:32px;
            padding-top:32px;padding-bottom:32px;
            max-width:620px;
        }
        .fct_body_start > table {
            max-width:620px;
            margin-bottom: 20px;
            margin-left:auto;
            margin-right:auto;
        }
    </style>
</head>
<body>
<?php if (!empty($preheader)): ?>
    <div style="display:none;overflow:hidden;line-height:1px;opacity:0;max-height:0;max-width:0" data-skip-in-text="true">
        <?php echo esc_attr($preheader); ?>
    </div>
<?php endif; ?>

<table class="email_wrap" align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="">
    <tbody>
    <tr style="width:100%">
        <td class="fct_body_start">
            <?php echo $emailBody; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped  ?>
        </td>
    </tr>
    </tbody>
</table>

<?php if (!empty($emailFooter)): ?>
    <table class="email_footer" align="center" width="100%" border="0" cellpadding="0" cellspacing="0"
           role="presentation"
           style="padding-top:20px;border-top-width:0px;border-style:solid;border-color:rgb(236,240,241); text-align:center">
        <tbody>
        <tr>
            <td>
                <?php echo $emailFooter; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped  ?>
            </td>
        </tr>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
