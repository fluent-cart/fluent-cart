<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentReceipt;
use FluentCart\App\Services\TemplateService;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCartPro\App\Modules\Licensing\Models\License;

class OrderParser extends BaseParser
{
    private StoreSettings $storeSettings;
    private $order;
    private $orderTz;

    private $licenses;

    private bool $licenseLoaded = false;

    private $subscriptions;

    private bool $subscriptionLoaded = false;

    public function __construct($data)
    {
        $this->storeSettings = new StoreSettings();
        $this->order = Arr::get($data, 'order');
        $config = Arr::wrap(
            Arr::get($this->order, 'config')
        );
        $rawTz = Arr::get($config, 'user_tz', 'UTC');
        $this->orderTz = (@timezone_open($rawTz) !== false) ? $rawTz : 'UTC';
        $orderId = Arr::get($this->order, 'id');


        parent::__construct($data);
    }

//    protected array $methodMap = [
//        'customer_dashboard_link' => 'getCustomerDashboardLink',
//        'payment_summary' => 'getPaymentSummary',
//        'payment_receipt' => 'getPaymentReceipt',
//    ];

    protected array $methodMap = [
        'item_count'         => 'getItemCount',
        'is_digital'         => 'getIsDigital',
        'store_vat_display'                => 'getStoreVatDisplay',
        'store_company_name'               => 'getStoreCompanyName',
        'store_company_display'            => 'getStoreCompanyDisplay',
        'store_legal_registration_id'      => 'getStoreLegalRegistrationId',
        'store_legal_registration_display' => 'getStoreLegalRegistrationDisplay',
        'store_seller_vat_id'              => 'getStoreSellerVatId',
        'store_seller_vat_display'         => 'getStoreSellerVatDisplay',
        'store_seller_tax_id'              => 'getStoreSellerTaxId',
        'store_tax_display'                => 'getStoreTaxDisplay',
        'buyer_vat_display'                => 'getBuyerVatDisplay',
        'buyer_company_name'               => 'getBuyerCompanyName',
        'buyer_legal_registration_id'      => 'getBuyerLegalRegistrationId',
        'buyer_reverse_charge_declaration' => 'getBuyerReverseChargeDeclaration',
        'tax_breakdown'                    => 'getTaxBreakdown',
        'fee_lines'                        => 'getFeeLines',
    ];

    protected array $attributeMap = [
        'id'         => 'order.id',
        'status'     => 'order.status',
        'created_at' => 'order.created_at',
        'updated_at' => 'order.updated_at',
    ];

    protected array $centColumns = [
        'total_amount',
        'subtotal',
        'discount_tax',
        'manual_discount_total',
        'coupon_discount_total',
        'shipping_tax',
        'shipping_total',
        'fee_total',
        'tax_total',
        'total_paid',
        'total_refund'
    ];

    public function parse($accessor = '', $code = '', $transformer = null): ?string
    {

        if ($this->shouldParseAddress($accessor)) {
            return $this->parseAddressFields($accessor);
        }

        if (in_array($accessor, ['updated_at', 'created_at'])) {
            $date = Arr::get($this->data, $this->attributeMap[$accessor]);
            $timestamp = DateTime::anyTimeToGmt($date)->getTimestamp();

            $date = wp_date(
                get_option('date_format'),
                $timestamp,
                new \DateTimeZone($this->orderTz)
            );
            
            return Helper::translateNumber($date);
        }

        // Handle _formatted suffix for cent columns (e.g. total_amount_formatted)
        $formattedSuffix = '_formatted';
        if (Str::endsWith($accessor, $formattedSuffix)) {
            $baseAccessor = substr($accessor, 0, -strlen($formattedSuffix));
            if (in_array($baseAccessor, $this->centColumns)) {
                $amount = Arr::get($this->order, $baseAccessor);
                if (!is_numeric($amount)) {
                    return (string) $amount;
                }
                return CurrencySettings::getPriceHtml($amount, $this->order['currency']);
            }
        }

        if (in_array($accessor, $this->centColumns)) {
            $amount = Arr::get($this->order, $accessor);
            if (!is_numeric($amount)) {
                return (string) $amount;
            }
            return (string) ($amount / 100);
        }

        // $html parsers
        $htmlParsers = [
            'order.download_details',
            'order.items_table',
            'order.payment_summary',
            'order.payment_receipt',
            'order.subscription_details',
            'order.license_details',
            'order.address_details',
        ];

        if (in_array($code, $htmlParsers)) {

            $order = $this->order;

            if ($code == 'order.items_table') {
                return \FluentCart\App\App::make('view')->make('emails.parts.items_table', [
                    'order'          => $order,
                    'formattedItems' => $order->order_items,
                    'heading'        => __('Order Summary', 'fluent-cart'),
                ]);
            }

            if ($code === 'order.subscription_details') {
                if ($order->subscriptions && $order->subscriptions->count() > 0) {
                    return \FluentCart\App\App::make('view')->make('invoice.parts.subscription_items', [
                        'subscriptions' => $order->subscriptions,
                        'order'         => $order
                    ]);
                }
                return '';
            }

            if ($code === 'order.license_details') {
                $licenses = $order->getLicenses();
                if ($licenses && $licenses->count() > 0) {
                    return \FluentCart\App\App::make('view')->make('emails.parts.licenses', [
                        'licenses'    => $licenses,
                        'heading'     => _n('License', 'Licenses', $licenses->count(), 'fluent-cart'),
                        'show_notice' => false
                    ]);
                }
                return '';
            }

            if ($code === 'order.download_details') {
                $downloads = $order->getDownloads();
                if ($downloads) {
                    return \FluentCart\App\App::make('view')->make('emails.parts.downloads', [
                        'order'         => $order,
                        'heading'       => _n('Download', 'Downloads', count($downloads), 'fluent-cart'),
                        'downloadItems' => $downloads,
                    ]);
                }
                return '';
            }

            if ($code === 'order.address_details') {
                return \FluentCart\App\App::make('view')->make('emails.parts.addresses', [
                    'order' => $order,
                ]);
            }

            if ($code == 'order.payment_summary') {
                return $this->getPaymentSummary();
            }
            if ($code == 'order.payment_receipt') {
                return $this->getPaymentReceipt();
            }
        }


        return $this->get($accessor, $code);
    }

    public function shouldParseAddress($accessor): bool
    {
        return Str::startsWith($accessor, 'billing.') || Str::startsWith($accessor, 'shipping.');
    }

    public function parseAddressFields($accessor)
    {
        list($addressType, $accessorsKey) = $this->resolveAddressFieldKeys($accessor);
        return $this->getAddressData($addressType, $accessorsKey);
    }

    public function resolveAddressFieldKeys($accessor): array
    {
        $exploded = explode('.', $accessor);
        $addressType = $exploded[0];
        $accessorsKey = implode('.', array_slice($exploded, 1));
        return [$addressType, $accessorsKey];
    }

    public function getAddressData($addressAccessor, $accessor = null)
    {
        $address = Arr::get($this->order, $addressAccessor . '_address');

        if (empty($address)) {
            return "";
        }

        $formattedFields = ['city', 'state', 'country'];
        if (in_array($accessor, $formattedFields) && method_exists($address, 'getFormattedAddress')) {
            $formatted = $address->getFormattedAddress();
            return Arr::get($formatted, $accessor) ?: '';
        }

        return Arr::get($address, $accessor) ?: '';
    }

    public function getPaymentSummary()
    {
        $order = $this->order;

        return \FluentCart\App\App::make('view')->make('emails.parts.items_table', [
            'order'          => $order,
            'formattedItems' => $order->order_items,
            'heading'        => '',
        ]);
    }

    public function getPaymentReceipt()
    {
        $order = $this->order;

        ob_start();

        \FluentCart\App\App::make('view')->render('emails.parts.items_table', [
            'order'          => $order,
            'formattedItems' => $order->order_items,
            'heading'        => __('Order Summary', 'fluent-cart'),
        ]);


        if ($order->subscriptions && $order->subscriptions->count() > 0) {
            \FluentCart\App\App::make('view')->render('invoice.parts.subscription_items', [
                'subscriptions' => $order->subscriptions,
                'order'         => $order
            ]);
        }

        $licenses = $order->getLicenses();
        if ($licenses && $licenses->count() > 0) {
            \FluentCart\App\App::make('view')->render('emails.parts.licenses', [
                'licenses'    => $licenses,
                'heading'     => __('Licenses', 'fluent-cart'),
                'show_notice' => false
            ]);
        }

        $downloads = $order->getDownloads();
        if ($downloads) {
            \FluentCart\App\App::make('view')->render('emails.parts.downloads', [
                'order'         => $order,
                'heading'       => __('Downloads', 'fluent-cart'),
                'downloadItems' => $downloads,
            ]);
        }

        echo '<hr />';

        \FluentCart\App\App::make('view')->render('emails.parts.addresses', [
            'order' => $order,
        ]);

        return ob_get_clean();


    }

    public function getDiscountTotal(): string
    {
        return (string) ($this->getDiscountTotalInCents() / 100);
    }

    public function getDiscountTotalFormatted(): string
    {
        return CurrencySettings::getPriceHtml($this->getDiscountTotalInCents(), $this->order['currency']);
    }

    private function getDiscountTotalInCents(): int
    {
        return (int) Arr::get($this->order, 'coupon_discount_total', 0)
             + (int) Arr::get($this->order, 'manual_discount_total', 0);
    }

    public function getOrderRef(): string
    {
        $invoiceNo = Arr::get($this->order, 'invoice_no');

        if (!empty($invoiceNo)) {
            return (string) $invoiceNo;
        }

        return (string) Arr::get($this->order, 'id');
    }

    public function getCustomerDashboardAnchorLink($accessor, $code = null, $conditions = [])
    {
        $defaultValue = Arr::get($conditions, 'default_value') ?? Arr::get($this->order, 'invoice_no');
        if (empty($this->order)) {
            return $code;
        }

        $profilePage = $this->storeSettings->getCustomerProfilePage();


        if (!empty($profilePage)) {
            return "<a style='color: #017EF3; text-decoration: none;' href='" . "$profilePage#/order/" . Arr::get($this->order, 'uuid') . "'>" . $defaultValue . "</a>";
        } else {
            return Arr::get($this->order, 'invoice_no');
        }

    }

    public function getCustomerDashboardLink($accessor, $code = null)
    {
        if (empty($this->order)) {
            return $code;
        }

        $orderLink = TemplateService::getCustomerProfileUrl('order/' . Arr::get($this->order, 'uuid'));

        return is_user_logged_in() ? $orderLink : wp_login_url($orderLink);
    }

    public function getPaymentLink($accessor = null, $code = null)
    {
        if (empty($this->order)) {
            return $code;
        }

        return PaymentHelper::getCustomPaymentLink(Arr::get($this->order, 'uuid'));
    }

    public function getAdminOrderLink($accessor, $code = null)
    {
        if (empty($this->order)) {
            return $code;
        }
        return admin_url('admin.php?page=fluent-cart#/orders/' . Arr::get($this->order, 'id') . '/view');
    }

    public function getAdminOrderAnchorLink($accessor, $code = null, $conditions = [])
    {
        $defaultValue = Arr::get($conditions, 'default_value');
        if (empty($this->order)) {
            return $code;
        }

        $url = admin_url('admin.php?page=fluent-cart#/orders/' . Arr::get($this->order, 'id') . '/view');

        if (!empty($defaultValue)) {
            return "<a style='color: #017EF3; text-decoration: none;' href='" . $url . "'>" . $defaultValue . "</a>";
        }

        return $url;
    }

    public function getCustomerOrderLink($accessor, $code = null)
    {
        if (empty($this->order)) {
            return $code;
        }

        $customerProfilePage = $this->storeSettings->getCustomerProfilePage();
        $orderLink = $customerProfilePage . '#/order/' . Arr::get($this->order, 'uuid');

        return is_user_logged_in() ? $orderLink : wp_login_url($orderLink);
    }

    public function getTotalAmount()
    {
        $total = ($this->order['total_amount'] / 100);
        $currency_sign = $this->order['currency'];
        return $total . $currency_sign;
    }

    public function getDownloads()
    {
        $order = $this->order;

        $downloads = $order->getDownloads();
        if ($downloads) {
            return (string)\FluentCart\App\App::make('view')->make('emails.parts.downloads', [
                'order'         => $order,
                'heading'       => '',
                'downloadItems' => $downloads
            ]);
        }

        return '';

    }

    public function getLicenses()
    {
        $order = $this->order;
        $licenses = $order->getLicenses();
        if ($licenses && $licenses->count() > 0) {
            return (string)\FluentCart\App\App::make('view')->make('emails.parts.licenses', [
                'licenses'    => $licenses,
                'heading'     => __('Licenses', 'fluent-cart'),
                'show_notice' => false
            ]);
        }

        return '';
    }

    public function getLicenseCount(): string
    {
        return (string)$this->licenses->count();
    }

    public function getIsDigital(): string
    {
        if (!$this->order) {
            return 'no';
        }

        $fulfillmentType = Arr::get($this->order, 'fulfillment_type');

        return $fulfillmentType === 'digital' ? 'yes' : 'no';
    }

    public function getItemCount(): string
    {
        $orderItems = $this->order ? $this->order->order_items : null;

        if ($orderItems) {
            return (string)$orderItems->count();
        }

        return '0';
    }

    public function getPaymentMethodTitle(): string
    {
        if (!$this->order) {
            return '';
        }

        $title = (string) Arr::get($this->order, 'payment_method_title', '');
        if ($title !== '') {
            return $title;
        }

        $slug = (string) Arr::get($this->order, 'payment_method', '');
        if ($slug === '') {
            return '';
        }

        if (class_exists(GatewayManager::class)) {
            $gateway = GatewayManager::getInstance($slug);
            if ($gateway) {
                $gatewayTitle = (string) $gateway->getMeta('title');
                if ($gatewayTitle !== '') {
                    return $gatewayTitle;
                }
            }
        }

        return ucwords(str_replace(['_', '-'], ' ', $slug));
    }

    public function getFeeLines(): string
    {
        if (!$this->order) {
            return '';
        }

        $currency  = (string) Arr::get($this->order, 'currency', '');
        $feeItems  = $this->order->feeItems()->get();

        if ($feeItems->isEmpty()) {
            return '';
        }

        $rowStyle  = 'padding:3px 0;font-size:11px;font-weight:600;color:#525866;';
        $valueStyle = 'text-align:right;';
        $rows = '';

        foreach ($feeItems as $feeItem) {
            $rows .= '<tr>'
                   . '<td style="' . $rowStyle . '">' . esc_html((string) $feeItem->title) . '</td>'
                   . '<td style="' . $rowStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml((int) $feeItem->subtotal, $currency) . '</td>'
                   . '</tr>';
        }

        return $rows;
    }

    public function getTaxBreakdown(): string
    {
        if (!$this->order) {
            return '';
        }

        $currency  = (string) Arr::get($this->order, 'currency', '');
        $summary   = TaxSummaryHelper::computeTaxSummary($this->order);

        if (!$summary['shouldRender']) {
            return '';
        }

        $headingStyle  = 'padding:8px 0 4px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;';
        $mutedStyle    = 'padding:3px 0;font-size:11px;color:#94a3b8;';
        $normalStyle   = 'padding:3px 0;font-size:11px;font-weight:600;color:#525866;';
        $totalStyle    = 'padding:6px 0 3px;font-size:11px;font-weight:700;color:#0E121B;border-top:1px solid #e2e8f0;';
        $valueStyle    = 'text-align:right;';

        $rows = '';

        $foldedRateLines  = Arr::get($summary, 'foldedRateLines', []);
        $isRcOrder        = !empty($summary['isReverseCharge']);
        $rcReversedTotal  = isset($summary['reversedTaxTotal']) ? (int) $summary['reversedTaxTotal'] : 0;
        $rcReversedValue  = $rcReversedTotal > 0
            ? esc_html(Helper::toDecimal($rcReversedTotal))
            : esc_html__('Charge reversed', 'fluent-cart');
        $includedInPrices = (int) Arr::get($summary, 'includedInPrices', 0);
        $opFeeRows        = Arr::get($summary, 'feeTaxLineRows', []);
        $taxRateLines     = Arr::get($summary, 'taxRateLines', []);
        $productTaxRowCount = !empty($taxRateLines)
            ? count($taxRateLines)
            : (int) ($summary['inclusiveTax'] > 0) + (int) ($summary['exclusiveTax'] > 0);
        $rowCount = $productTaxRowCount + count($opFeeRows) + (int) ($summary['shippingTax'] > 0);
        $shippingTaxLines = Arr::get($summary, 'shippingTaxLines', []);
        $shouldShowBreakdown = !empty($taxRateLines)
            || !empty($shippingTaxLines)
            || $rowCount >= 2
            || ($rowCount === 1 && !($summary['payableTax'] > 0 || $summary['inclusiveTax'] > 0 || (int) Arr::get($summary, 'inclusiveFeeTax', 0) > 0));

        $isSimplifiedMode = ($summary['displayMode'] ?? '') === 'simplified';

        if ($isSimplifiedMode && !empty($summary['simpleLine'])) {
            $rows .= '<tr>'
                   . '<td style="' . $normalStyle . '">' . esc_html((string) $summary['simpleLine']['label']) . '</td>'
                   . '<td style="' . $normalStyle . $valueStyle . '">' . esc_html((string) $summary['simpleLine']['value']) . '</td>'
                   . '</tr>';

            // Simplified mode: single tax line only, skip the detailed breakdown below.
            return $this->wrapTaxBreakdownBox($rows);
        }

        if (!empty($foldedRateLines)) {
            $colHeadStyle     = 'width:58%;padding:3px 8px 3px 0;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;vertical-align:top;';
            $colHeadBaseStyle = 'width:24%;padding:3px 8px 3px 0;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;text-align:right;white-space:nowrap;vertical-align:top;';
            $colHeadTaxStyle  = 'width:18%;padding:3px 0;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;text-align:right;white-space:nowrap;vertical-align:top;';

            $rows .= '<tr><td colspan="2" style="padding:5px 0 2px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;">'
                   . esc_html__('Tax breakdown by rate', 'fluent-cart')
                   . '</td></tr>';

            $nestedTable  = '<table width="100%" cellpadding="0" cellspacing="0" style="border:none;border-collapse:collapse;table-layout:fixed;">';
            $nestedTable .= '<tr>'
                          . '<td style="' . $colHeadStyle . '">' . esc_html__('Rate', 'fluent-cart') . '</td>'
                          . '<td style="' . $colHeadBaseStyle . '">' . esc_html__('Taxable base', 'fluent-cart') . '</td>'
                          . '<td style="' . $colHeadTaxStyle . '">' . esc_html__('Tax', 'fluent-cart') . '</td>'
                          . '</tr>';

            foreach ($foldedRateLines as $foldedRow) {
                $foldedStyle  = !empty($foldedRow['inclusive']) ? $mutedStyle : $normalStyle;
                $foldedStyleR = $foldedStyle . $valueStyle;
                $nestedTable .= '<tr>'
                              . '<td style="width:58%;' . $foldedStyle . 'padding-right:8px;white-space:normal;word-break:break-word;overflow-wrap:break-word;vertical-align:top;">' . esc_html((string) $foldedRow['label']) . '</td>'
                              . '<td style="width:24%;' . $foldedStyleR . 'padding-right:8px;white-space:nowrap;vertical-align:top;">' . CurrencySettings::getPriceHtml((int) $foldedRow['base'], $currency) . '</td>'
                              . '<td style="width:18%;' . $foldedStyleR . 'white-space:nowrap;vertical-align:top;">' . CurrencySettings::getPriceHtml((int) $foldedRow['tax'], $currency) . '</td>'
                              . '</tr>';
            }
            $nestedTable .= '</table>';

            $rows .= '<tr><td colspan="2" style="padding:2px 0 0 0;">' . $nestedTable . '</td></tr>';

            if ($isRcOrder) {
                $rcReversedTotalValue = $rcReversedTotal > 0
                    ? CurrencySettings::getPriceHtml($rcReversedTotal, $currency)
                    : esc_html__('Charge reversed', 'fluent-cart');
                $rows .= '<tr>'
                       . '<td style="' . $totalStyle . '">' . esc_html__('VAT reversed', 'fluent-cart') . '</td>'
                       . '<td style="' . $totalStyle . $valueStyle . '">' . $rcReversedTotalValue . '</td>'
                       . '</tr>';
                return $this->wrapTaxBreakdownBox($rows);
            }

            $rows .= '<tr>'
                   . '<td style="' . $totalStyle . '">' . esc_html__('Total tax', 'fluent-cart') . '</td>'
                   . '<td style="' . $totalStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml((int) $summary['totalOrderTax'], $currency) . '</td>'
                   . '</tr>';

            if ($includedInPrices > 0) {
                $rows .= '<tr>'
                       . '<td style="' . $mutedStyle . '">' . esc_html__('of which included in prices', 'fluent-cart') . '</td>'
                       . '<td style="' . $mutedStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml($includedInPrices, $currency) . '</td>'
                       . '</tr>';
            }

            if ($summary['payableTax'] > 0 && $includedInPrices > 0) {
                $payableStyle = $totalStyle;
                $rows .= '<tr>'
                       . '<td style="' . $payableStyle . '">' . esc_html__('Payable now (added)', 'fluent-cart') . '</td>'
                       . '<td style="' . $payableStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml((int) $summary['payableTax'], $currency) . '</td>'
                       . '</tr>';
            }

            return $this->wrapTaxBreakdownBox($rows);
        }

        if ($isRcOrder) {
            $rows .= '<tr><td colspan="2" style="' . $headingStyle . '">'
                   . esc_html__('TAX SUMMARY', 'fluent-cart')
                   . '</td></tr>';
            $rows .= '<tr>'
                   . '<td style="' . $totalStyle . '">' . esc_html__('Tax reversed', 'fluent-cart') . '</td>'
                   . '<td style="' . $totalStyle . $valueStyle . '">' . $rcReversedValue . '</td>'
                   . '</tr>';
            return $this->wrapTaxBreakdownBox($rows);
        }

        $rows .= '<tr><td colspan="2" style="' . $headingStyle . '">'
              . esc_html__('TAX SUMMARY', 'fluent-cart')
              . '</td></tr>';

        if (!empty($taxRateLines) && $shouldShowBreakdown) {
            foreach ($taxRateLines as $taxLine) {
                $taxLineStyle = !empty($taxLine['inclusive']) ? $mutedStyle : $normalStyle;
                $rows .= '<tr>'
                       . '<td style="' . $taxLineStyle . '">' . esc_html($taxLine['label']) . '</td>'
                       . '<td style="' . $taxLineStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml($taxLine['order_tax'], $currency) . '</td>'
                       . '</tr>';
            }
        }

        if (empty($taxRateLines) && $summary['inclusiveTax'] > 0 && $shouldShowBreakdown) {
            $rows .= '<tr>'
                   . '<td style="' . $mutedStyle . '">' . esc_html__('Included in item prices', 'fluent-cart') . '</td>'
                   . '<td style="' . $mutedStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml($summary['inclusiveTax'], $currency) . '</td>'
                   . '</tr>';
        }

        if (empty($taxRateLines) && $summary['exclusiveTax'] > 0 && $shouldShowBreakdown) {
            $rows .= '<tr>'
                   . '<td style="' . $normalStyle . '">' . esc_html__('Added on products', 'fluent-cart') . '</td>'
                   . '<td style="' . $normalStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml($summary['exclusiveTax'], $currency) . '</td>'
                   . '</tr>';
        }

        if ($shouldShowBreakdown) {
            foreach ($opFeeRows as $feeRow) {
                $feeLineStyle = $feeRow['inclusive'] ? $mutedStyle : $normalStyle;
                $rows .= '<tr>'
                       . '<td style="' . $feeLineStyle . '">' . esc_html($feeRow['display_label']) . '</td>'
                       . '<td style="' . $feeLineStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml($feeRow['tax_amount'], $currency) . '</td>'
                       . '</tr>';
            }
        }

        if ($summary['shippingTax'] > 0 && $shouldShowBreakdown) {
            $isShippingInclusive = TaxSummaryHelper::isShippingTaxInclusive($this->order);
            $shippingRowStyle    = $isShippingInclusive ? $mutedStyle : $normalStyle;
            if (!empty($shippingTaxLines)) {
                foreach ($shippingTaxLines as $shippingTaxLine) {
                    $rows .= '<tr>'
                           . '<td style="' . $shippingRowStyle . '">' . esc_html($shippingTaxLine['label']) . '</td>'
                           . '<td style="' . $shippingRowStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml($shippingTaxLine['shipping_tax'], $currency) . '</td>'
                           . '</tr>';
                }
            } else {
                $shippingRowLabel = $isShippingInclusive
                    ? esc_html__('Included in shipping prices', 'fluent-cart')
                    : esc_html__('Added on shipping', 'fluent-cart');
                $rows .= '<tr>'
                       . '<td style="' . $shippingRowStyle . '">' . $shippingRowLabel . '</td>'
                       . '<td style="' . $shippingRowStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml($summary['shippingTax'], $currency) . '</td>'
                       . '</tr>';
            }
        }

        if ($summary['payableTax'] > 0) {
            $rows .= '<tr>'
                   . '<td style="' . $totalStyle . '">' . esc_html__('Total payable tax', 'fluent-cart') . '</td>'
                   . '<td style="' . $totalStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml($summary['payableTax'], $currency) . '</td>'
                   . '</tr>';
        }

        if ($summary['inclusiveTax'] > 0 || !empty($summary['inclusiveFeeTax'])) {
            $rows .= '<tr>'
                   . '<td style="' . $mutedStyle . '">' . esc_html__('Total tax in this order', 'fluent-cart') . '</td>'
                   . '<td style="' . $mutedStyle . $valueStyle . '">' . CurrencySettings::getPriceHtml($summary['totalOrderTax'], $currency) . '</td>'
                   . '</tr>';
        }

        return $this->wrapTaxBreakdownBox($rows);
    }

    /**
     * Wrap the accumulated tax-breakdown rows in their own grey rounded box,
     * right-aligned to a fixed 400px column.
     *
     * The tax breakdown is the only thing that lives inside the grey box; the
     * surrounding Subtotal/Discount/Shipping/Fees rows (above) and Refund/Total/
     * Payment rows (below) are rendered as plain rows by the summary table and
     * stay outside this wrapper. A table-based 400px right-aligned wrapper is used
     * (not a div) so PDF renderers (mPDF/Dompdf) and email clients honour the
     * width/alignment, matching the boxed treatment on the thank-you/customer
     * surfaces. Consumed by the PDF receipt templates via {{order.tax_breakdown}}.
     *
     * @param string $rows Inner <tr> rows for the tax breakdown.
     * @return string A single <tr><td colspan="2"> cell carrying the grey box.
     */
    private function wrapTaxBreakdownBox(string $rows): string
    {
        if ($rows === '') {
            return '';
        }

        $boxStyle = 'background-color:rgb(249,250,251);padding:16px;border-radius:8px;';

        $nestedTable = '<table width="100%" cellpadding="0" cellspacing="0" style="width:100%;border:none;border-collapse:collapse;">'
                     . $rows
                     . '</table>';

        return '<tr><td colspan="2" style="padding:8px 0 0 0;">'
             . '<table align="right" width="400" cellpadding="0" cellspacing="0" style="width:400px;max-width:100%;border:none;border-collapse:collapse;margin:0 0 0 auto;">'
             . '<tr><td style="' . $boxStyle . '">' . $nestedTable . '</td></tr>'
             . '</table>'
             . '</td></tr>';
    }

    private function resolveTaxRateLabel($orderTaxRate): string
    {
        $taxRate = $orderTaxRate->tax_rate ?? null;
        if ($taxRate) {
            $name = (string) Arr::get($taxRate, 'name', '');
            $rate = Arr::get($taxRate, 'rate');
            if ($name !== '' && is_numeric($rate)) {
                $rate = (string) $rate;

                if (strpos($rate, '.') !== false) {
                    $rate = rtrim(rtrim($rate, '0'), '.');
                }

                return $name . ' (' . $rate . '%)';
            }
            if ($name !== '') {
                return $name;
            }
        }

        return __('Tax', 'fluent-cart');
    }

    public function getSubscriptions()
    {
        return '';
    }

    /**
     * Returns formatted store VAT display string, e.g. "VAT: NL123456789B01".
     * Returns empty string if no store VAT is configured for this order.
     */
    public function getStoreVatDisplay(): string
    {
        if (!$this->order) {
            return '';
        }

        $orderTaxRate = $this->order->orderTaxRates ? $this->order->orderTaxRates->first() : null;

        if (!$orderTaxRate) {
            return '';
        }

        $storeVatNumber = Arr::get($orderTaxRate->meta ?? [], 'store_vat_number', '');

        if (empty($storeVatNumber)) {
            return '';
        }

        $taxCountry = Arr::get($orderTaxRate->meta ?? [], 'tax_country', '');
        $label = \FluentCart\App\Modules\Tax\TaxModule::getCountryTaxTitle($taxCountry);

        return esc_html($label) . ': ' . esc_html($storeVatNumber);
    }

    /**
     * Returns formatted buyer VAT display string, e.g. "VAT/Tax ID: XX123456".
     * Checks business_info first, then falls back to legacy VAT storage.
     */
    public function getBuyerVatDisplay(): string
    {
        if (!$this->order) {
            return '';
        }

        $vatNumber = $this->order->getCustomerTaxNumber();
        if (!empty($vatNumber)) {
            $label = __('VAT/Tax ID', 'fluent-cart');
            return esc_html($label) . ': ' . esc_html($vatNumber);
        }

        return '';
    }

    /**
     * Returns buyer company name from billing address meta or VAT reverse charge data.
     */
    public function getBuyerCompanyName(): string
    {
        if (!$this->order) {
            return '';
        }

        // Check billing address meta first
        if ($this->order->billing_address) {
            $companyName = Arr::get($this->order->billing_address->meta ?? [], 'other_data.company_name', '');
            if (!empty($companyName)) {
                return esc_html($companyName);
            }
        }

        return esc_html($this->order->getCustomerTaxName());
    }

    public function getBuyerLegalRegistrationId(): string
    {
        if (!$this->order) {
            return '';
        }

        if ($this->order->billing_address) {
            $regId = Arr::get($this->order->billing_address->meta ?? [], 'other_data.legal_registration_id', '');
            if (!empty($regId)) {
                return esc_html($regId);
            }
        }

        return esc_html(Arr::get($this->order->getBusinessInfo(), 'legal_registration_id', ''));
    }

    public function getBuyerReverseChargeDeclaration(): string
    {
        if (!$this->order) {
            return '';
        }

        return esc_html(Arr::get($this->order->getBusinessInfo(), 'reverse_charge_declaration', ''));
    }

    // -------------------------------------------------------------------------
    // Store business info — reads from snapshotted fct_order_meta[store_business_info]
    // with live StoreSettings fallback for orders placed before this feature.
    // -------------------------------------------------------------------------

    private function getStoreBusinessField(string $field): string
    {
        if (!$this->order) {
            return '';
        }
        $snapshot = $this->order->getMeta('store_business_info', false);
        if ($snapshot !== false && isset($snapshot[$field])) {
            return (string) $snapshot[$field];
        }
        return (string) $this->storeSettings->get($field, '');
    }

    public function getStoreCompanyName(): string
    {
        return esc_html($this->getStoreBusinessField('company_name'));
    }

    public function getStoreCompanyDisplay(): string
    {
        $val = $this->getStoreBusinessField('company_name');
        if (empty($val)) {
            return '';
        }
        /* translators: %1$s: store company name */
        return esc_html(sprintf(__('Company: %1$s', 'fluent-cart'), $val));
    }

    public function getStoreLegalRegistrationId(): string
    {
        return esc_html($this->getStoreBusinessField('legal_registration_id'));
    }

    public function getStoreLegalRegistrationDisplay(): string
    {
        $val = $this->getStoreBusinessField('legal_registration_id');
        if (empty($val)) {
            return '';
        }
        /* translators: %1$s: store legal registration ID */
        return esc_html(sprintf(__('Reg. ID: %1$s', 'fluent-cart'), $val));
    }

    public function getStoreSellerVatId(): string
    {
        return esc_html($this->getStoreBusinessField('seller_vat_id'));
    }

    public function getStoreSellerVatDisplay(): string
    {
        $val = $this->getStoreBusinessField('seller_vat_id');
        if (empty($val)) {
            return '';
        }
        /* translators: %1$s: store seller VAT ID */
        return esc_html(sprintf(__('VAT ID: %1$s', 'fluent-cart'), $val));
    }

    public function getStoreSellerTaxId(): string
    {
        return esc_html($this->getStoreBusinessField('seller_tax_id'));
    }

    public function getStoreTaxDisplay(): string
    {
        $val = $this->getStoreBusinessField('seller_tax_id');
        if (empty($val)) {
            return '';
        }
        /* translators: %1$s: store seller tax ID */
        return esc_html(sprintf(__('Tax ID: %1$s', 'fluent-cart'), $val));
    }

}
