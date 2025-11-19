<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
use FluentCart\Framework\Support\Arr;

$billingAddress = $order->billing_address;
$shippingAddress = $order->shipping_address;

if(!$billingAddress && !$shippingAddress) {
    return;
}

?>
<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
       style="margin-bottom:24px">
    <tbody style="vertical-align: top;">
    <tr>
        <td>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tbody style="width:100%; vertical-align: top;">
                <tr style="width:100%">
                    <td style="width:50%;padding-left: 0;padding-right: 10px;">
                        <p style="font-size:12px;color:rgb(127,140,141);margin:0px;margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
                            <?php echo esc_html('Billing Address', 'fluent-cart'); ?>
                        </p>
                        <p style="font-size:14px;color:rgb(44,62,80);margin:0px;line-height:1.4;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php
                            if ($order->billing_address) {
                                echo esc_html($order->billing_address->getAddressAsText());
                            } else {
                                echo esc_html__('n/a', 'fluent-cart');
                            }
                            ?>
                        </p>

                        <?php
                            $orderTaxRates = $order->orderTaxRates->first();
                            $companyName = Arr::get($order->billing_address->meta ?? [], 'other_data.company_name', '');
                            $phone = Arr::get($order->billing_address->meta ?? [], 'other_data.phone', '');

                            if ($companyName == '') {
                                $companyName = Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.name', '');
                            }
                            
                            // show phone number
                            if ($phone !== ''):
                            ?>
                                <p style="font-size:12px;margin:0px;margin-top:8px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
                                    <?php echo esc_html($phone); ?>
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
                            if ($orderTaxRates) :
                                $vatNumber = Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.vat_number', '');
                                if ($vatNumber !== '') :
                                ?>
                                    <p style="font-size:12px;margin:0px;margin-top:8px;text-transform:uppercase;letter-spacing:1px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
                                        <?php echo esc_html__('EU VAT', 'fluent-cart') . ': ' . esc_html($vatNumber); ?>
                                    </p>
                                <?php
                                endif;
                            endif;
                        ?>
                    </td>
                    <td style="width:50%;padding-right: 0;padding-left: 10px;">
                        <?php if ($order->fulfillment_type !== 'digital'): ?>
                            <p style="font-size:12px;color:rgb(127,140,141);margin:0px;margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
                                <?php echo esc_html('Shipping Address', 'fluent-cart'); ?>
                            </p>
                            <p style="font-size:14px;color:rgb(44,62,80);margin:0px;line-height:1.4;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                                <?php
                                if ($order->shipping_address) {
                                    echo esc_html($order->shipping_address->getAddressAsText());
                                } else {
                                    echo esc_html__('n/a', 'fluent-cart');
                                }
                                $phone = Arr::get($order->shipping_address->meta ?? [], 'other_data.phone', '');
                                if ($phone !== ''):
                                ?>
                                    <p style="font-size:12px;margin:0px;margin-top:8px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
                                        <?php echo esc_html($phone); ?>
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
