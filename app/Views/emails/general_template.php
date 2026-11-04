<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php $fctEmailDir = \FluentCart\App\Services\Email\EmailDirection::dir(); ?>
<html dir="<?php echo esc_attr($fctEmailDir); ?>" lang="<?php echo esc_attr(\FluentCart\App\Services\Email\EmailDirection::lang()); ?>">
<head>
    <meta content="text/html; charset=UTF-8" http-equiv="Content-Type">
    <meta name="x-apple-disable-message-reformatting">
    <style>
        html {
            background-color: rgb(241, 243, 246);
            font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
        }
        body {
            background-color: rgb(241, 243, 246);
            margin: 0;
            padding: 32px 12px;
            font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
        }

        p {
            font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
            font-size: 14px;
            color: rgb(55, 65, 81);
            margin: 0px;
            margin-bottom: 8px;
            line-height: 24px;
        }

        .email_footer p {
            font-size: 13px;
            color: rgb(75, 85, 99);
            margin: 0px;
            line-height: 24px;
            margin-bottom: 8px;
        }

        hr {
            margin: 24px 0;
            width: 100%;
            border: none;
            border-top: 1px solid rgb(229, 231, 235);
        }

        .space_bottom_30 {
            display: block;
            width: 100%;
            margin-bottom: 30px;
        }

        /* Email intro; the greeting line carries a class so a plain opening sentence stays plain. */
        .space_bottom_30 p {
            font-size: 15px;
            line-height: 24px;
            color: rgb(55, 65, 81);
        }

        .space_bottom_30 p.fct-email-greeting {
            font-size: 16px;
            font-weight: 600;
            color: rgb(17, 24, 39);
            margin-bottom: 8px;
        }

        /* Clients that read <style> get a tighter card on phones; the rest keep 32px. */
        @media only screen and (max-width: 480px) {
            .email_wrap_inner {
                padding: 24px 20px !important;
            }
        }
    </style>
</head>
<body dir="<?php echo esc_attr($fctEmailDir); ?>">
<?php if (!empty($preheader)): ?>
    <div style="display:none;overflow:hidden;line-height:1px;opacity:0;max-height:0;max-width:0"
         data-skip-in-text="true">
        <?php echo esc_attr($preheader); ?>
    </div>
<?php endif; ?>

<?php if (!empty($header)): ?>
    <?php echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped  ?>
<?php endif; ?>

<table class="email_wrap" dir="<?php echo esc_attr($fctEmailDir); ?>" align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
       style="background-color:rgb(255,255,255);margin-left:auto;margin-right:auto;max-width:620px;border:1px solid rgb(229,231,235);border-radius:12px;border-collapse:separate;">
    <tbody>
    <tr style="width:100%">
        <?php // Padding on the cell, not the table: table padding adds to its 100% width and overflows on phones. ?>
        <td class="email_wrap_inner" style="padding:32px;">
            <?php echo $emailBody; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped  ?>
        </td>
    </tr>
    </tbody>
</table>

<?php if (!empty($emailFooter)): ?>
    <table class="email_footer" dir="<?php echo esc_attr($fctEmailDir); ?>" align="center" width="100%" border="0" cellpadding="0" cellspacing="0"
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
