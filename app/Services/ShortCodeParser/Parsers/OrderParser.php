<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentReceipt;
use FluentCart\App\Services\TemplateService;
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
        $this->orderTz = Arr::get($config, 'user_tz', 'UTC');
        $orderId = Arr::get($this->order, 'id');


        parent::__construct($data);
    }

//    protected array $methodMap = [
//        'customer_dashboard_link' => 'getCustomerDashboardLink',
//        'payment_summary' => 'getPaymentSummary',
//        'payment_receipt' => 'getPaymentReceipt',
//    ];

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
        'tax_total',
        'total_paid',
        'discount_total',
        'total_refund'
    ];

    public function parse($accessor = '', $code = '', $transformer = null): ?string
    {

        if ($this->shouldParseAddress($accessor)) {
            return $this->parseAddressFields($accessor);
        }

        if (in_array($accessor, ['updated_at', 'created_at'])) {
            $date = Arr::get($this->data, $this->attributeMap[$accessor]);
            return DateTime::gmtToTimezone($date, $this->orderTz)->format('M d, Y');
        }

        if (in_array($accessor, $this->centColumns)) {
            $amount = Arr::get($this->order, $accessor);
            if (!is_numeric($amount)) {
                return $amount;
            }
            return CurrencySettings::getPriceHtml($amount, $this->order['currency']);
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

    public function getSubscriptions()
    {
        return '';
    }

}


