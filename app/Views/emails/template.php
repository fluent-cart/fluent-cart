<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width">
    <style type="text/css">
        body {
            font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif;
        }
        p {
            -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; color: #444; font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; font-weight: normal; padding: 0; mso-line-height-rule: exactly; line-height: 140%; font-size: 16px; margin: 0 0 15px 0;
        }
        .conf_section {
            margin-bottom: 15px;
        }
        ul li {
            font-size: 16px;
        }
        h5 {
            font-size: 18px;
            font-weight: bold;
            margin: 0 0 7px;
        }
        @media only screen and (max-width: 599px) {table.body .container {width: 95% !important;}.header {padding: 15px 15px 12px 15px !important;}.header img {width: 200px !important;height: auto !important;}.content, .aside {padding: 30px 40px 20px 40px !important;}}
    </style>
</head>
<body>
<table border="0" cellpadding="0" cellspacing="0" width="100%" height="100%" class="body">
    <tr style="padding: 0; vertical-align: top; text-align: left;">
        <td align="center" valign="top" class="body-inner">
            <!-- Container -->
            <table border="0" cellpadding="0" cellspacing="0" class="container" style="border-collapse: collapse; border-spacing: 0; padding: 0; vertical-align: top; mso-table-lspace: 0pt; mso-table-rspace: 0pt; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; width: 100%; margin: 0 auto 30px auto; margin: 0 auto; text-align: inherit;">
                <!-- Content -->
                <tr style="padding: 0; vertical-align: top; text-align: left;">
                    <td align="left" valign="top" class="content">
                        <div class="success">
                            <?php echo wp_kses_post($email_body); ?>
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
