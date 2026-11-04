<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
use FluentCart\Framework\Support\Arr;

$startSide = \FluentCart\App\Services\Email\EmailDirection::start();
$endSide = \FluentCart\App\Services\Email\EmailDirection::end();

$billingAddress = $order->billing_address;
$shippingAddress = $order->shipping_address;

if(!$billingAddress && !$shippingAddress) {
    return;
}
// The name in bold, then one line each: street, city, state + postcode, country.
$fctRenderAddressLines = function ($address) {
    $parts = $address->formatted_address;
    $lines = array_filter([
        implode(', ', array_filter([trim((string) $parts['address_1']), trim((string) $parts['address_2'])])),
        trim((string) $parts['city']),
        implode(', ', array_filter([trim((string) $parts['state']), trim((string) $parts['postcode'])])),
        trim((string) $parts['country']),
    ]);
    $html = '';
    if (trim((string) $parts['name']) !== '') {
        $html .= '<span style="font-weight:600;color:rgb(17,24,39);">' . esc_html($parts['name']) . '</span><br>';
    }
    return $html . implode('<br>', array_map('esc_html', $lines));
};

?>
<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
       style="margin-bottom:24px">
    <tbody style="vertical-align: top;">
    <tr>
        <td>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tbody style="width:100%; vertical-align: top;">
                <tr style="width:100%">
                    <td style="width:50%;padding-<?php echo esc_attr($startSide); ?>: 0;padding-<?php echo esc_attr($endSide); ?>: 10px;">
                        <p style="font-size:11px;font-weight:700;color:#64748b;margin:0 0 8px;text-transform:uppercase;letter-spacing:0.1em;line-height:16px;">
                            <?php echo esc_html__('Billing Address', 'fluent-cart'); ?>
                        </p>
                        <p style="font-size:14px;color:rgb(55,65,81);margin:0px;line-height:24px;">
                            <?php
                            if ($order->billing_address) {
                                echo $fctRenderAddressLines($order->billing_address); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per line
                            } else {
                                echo esc_html__('n/a', 'fluent-cart');
                            }
                            ?>
                        </p>

                        <?php
                            $companyName = Arr::get($order->billing_address->meta ?? [], 'other_data.company_name', '');
                            $phone = Arr::get($order->billing_address->meta ?? [], 'other_data.phone', '');

                            if ($companyName == '') {
                                $companyName = $order->getCustomerTaxName();
                            }
                            
                            // show phone number
                            if ($phone !== ''):
                            ?>
                                <p style="font-size:12px;margin:0px;margin-top:8px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
                                    <?php echo \FluentCart\App\Services\Email\EmailDirection::ltr($phone); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </p>
                            <?php endif;

                            // show company name
                            if ($companyName !== '') {
                                ?>
                                    <p style="font-size:12px;margin:0px;margin-top:8px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
                                        <?php echo esc_html($companyName); ?>
                                    </p>
                                <?php
                            }

                            // show order tax rates
                            $vatNumber = $order->getCustomerTaxNumber();
                            if ($vatNumber !== '') :
                            ?>
                                <p style="font-size:12px;margin:0px;margin-top:8px;text-transform:uppercase;letter-spacing:1px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
                                    <?php
                                    $vatLabel = __('VAT/Tax ID', 'fluent-cart');
                                    echo esc_html($vatLabel) . ': ' . esc_html($vatNumber);
                                    ?>
                                </p>
                            <?php
                            endif;

                            $legalRegId = Arr::get($order->billing_address->meta ?? [], 'other_data.legal_registration_id', '');
                            if ($legalRegId === '') {
                                $legalRegId = Arr::get($order->getBusinessInfo(), 'legal_registration_id', '');
                            }
                            if ($legalRegId !== ''):
                            ?>
                                <p style="font-size:12px;margin:0px;margin-top:8px;text-transform:uppercase;letter-spacing:1px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
                                    <?php echo esc_html__('Reg. ID', 'fluent-cart') . ': ' . esc_html($legalRegId); ?>
                                </p>
                            <?php
                            endif;

                            $reverseChargeDeclaration = Arr::get($order->getBusinessInfo(), 'reverse_charge_declaration', '');
                            if ($reverseChargeDeclaration !== ''):
                            ?>
                                <p style="font-size:12px;margin:0px;margin-top:8px;line-height:24px;">
                                    <strong><?php echo esc_html__('Reverse Charge Declaration', 'fluent-cart'); ?>:</strong>
                                    <?php echo esc_html($reverseChargeDeclaration); ?>
                                </p>
                            <?php
                            endif;
                        ?>
                    </td>
                    <td style="width:50%;padding-<?php echo esc_attr($endSide); ?>: 0;padding-<?php echo esc_attr($startSide); ?>: 10px;">
                        <?php if ($order->fulfillment_type !== 'digital'): ?>
                            <p style="font-size:11px;font-weight:700;color:#64748b;margin:0 0 8px;text-transform:uppercase;letter-spacing:0.1em;line-height:16px;">
                                <?php echo esc_html__('Shipping Address', 'fluent-cart'); ?>
                            </p>
                            <p style="font-size:14px;color:rgb(55,65,81);margin:0px;line-height:24px;">
                                <?php
                                if ($order->shipping_address) {
                                    echo $fctRenderAddressLines($order->shipping_address); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per line
                                } else {
                                    echo esc_html__('n/a', 'fluent-cart');
                                }
                                $phone = Arr::get($order->shipping_address->meta ?? [], 'other_data.phone', '');
                                if ($phone !== ''):
                                ?>
                                    <p style="font-size:12px;margin:0px;margin-top:8px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
                                        <?php echo \FluentCart\App\Services\Email\EmailDirection::ltr($phone); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </p>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                </tbody>
            </table>
        </td>
    </tr>
    </tbody>
</table>
