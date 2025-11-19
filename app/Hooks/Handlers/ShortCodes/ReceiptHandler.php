<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\Api\Orders;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderOperation;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Email\EmailNotifications;
use FluentCart\App\Services\FrontendView;
use FluentCart\App\Services\Renderer\Receipt\ReceiptRenderer;
use FluentCart\App\Services\Renderer\Receipt\ThankYouRender;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ReceiptHandler
{
    protected string $slug = '';
    protected string $assetsPath = '';

    protected $productId;

    public function register()
    {
        add_shortcode('fluent_cart_receipt', [$this, 'renderRedirectPage']);
    }

    public function renderRedirectPage($attributes): string
    {
        $isReceipt = Arr::get($attributes, 'type', '') === 'receipt';
        $request = App::request();
        $orderHash = sanitize_text_field($request->get('order_hash'));
        $transactionHash = sanitize_text_field($request->get('trx_hash'));

        do_action('fluent_cart/before_render_redirect_page', [
            'order_hash' => $orderHash,
            'trx_hash'   => $transactionHash,
            'method'      => sanitize_text_field($request->get('method')),
            'is_receipt'  => $isReceipt
        ]);


        $isEmailFooter = EmailNotifications::getSettings('show_email_footer');

        if (empty($transactionHash) && empty($orderHash)) {
            if ($request->get('action') === 'edit') {
                return '';
            }
            return $this->renderNotFoundPage();
        }

        if (!empty($orderHash)) {
            $order = (new Orders())->getBy('uuid', $orderHash);
        } else {
            $transaction = OrderTransaction::query()->where('uuid', $transactionHash)->first();
            if (!$transaction) {
                ob_start();
                FrontendView::renderNotFoundPage(
                    __('Not found.', 'fluent-cart'),
                    __('Sorry, no transaction found.', 'fluent-cart'),
                    __('The provided transaction appears invalid, expired or does’t match our records', 'fluent-cart')
                );
                return ob_get_clean();
                //return '<div class="fluent_cart_order_confirmation">No Transaction found!</div>';
            }
            $order = (new Orders())->getById($transaction->order_id);
        }

        if (empty($order)) {
            return $this->renderNotFoundPage();
        }

        $userId = get_current_user_id();
        $isOwn = $userId && $order->customer->user_id == $userId;

        $isAdmin = is_user_logged_in() && current_user_can('manage_options');
        $payment_status = Arr::get($order, 'payment_status');
        $orderPlacedAt = DateTime::anytimeToGmt(Arr::get($order, 'created_at'));
        $showOrder =
            $isOwn ||
            $isAdmin ||
            $payment_status === Status::PAYMENT_PAID ||
            DateTime::now() < $orderPlacedAt->addHours(2);


        if (!$showOrder) {
            return $this->renderNotFoundPage();
        }

        $lastTransaction = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', '=', Status::TRANSACTION_TYPE_CHARGE)
            ->orderBy('id', 'DESC')
            ->first();

        $order->last_transaction = $lastTransaction;

        $operation = OrderOperation::query()->where('order_id', $order->id)->first();

        $isFirstTime = false;
        if ($operation && !$operation->sales_recorded && $order->payment_status === Status::PAYMENT_PAID) {
            $operation->sales_recorded = 1;
            $operation->save();
            $isFirstTime = true;
        }

        $vatTaxId = $order->getMeta('vat_tax_id', '');


        ob_start();
        $defaultBannerImage = Vite::getAssetUrl('images/email-template/email-banner.png');
//
//        $templatePath = !$isReceipt ? 'invoice.thank_you' : 'invoice.receipt_slip';
//        App::make('view')->render($templatePath, [
//            'default_banner_image' => $defaultBannerImage,
//            'order'                => $order,
//            'is_first_time'        => $isFirstTime,
//            'vat_tax_id'           => $vatTaxId,
//            'order_operation'      => $operation
//        ]);
        $config = [
            'order'                => $order,
            'vat_tax_id'           => $vatTaxId,
            'order_operation'      => $operation,
            'is_first_time'        => $isFirstTime,
            'default_banner_image' => $defaultBannerImage
        ];
        if ($isReceipt) {
            (new ReceiptRenderer($config))->render();
        } else {
            (new ThankYouRender($config))->render();
        }

        $slipView = ob_get_clean();


        $parsedContent = ShortcodeTemplateBuilder::make($slipView, [
            'order' => $order,
        ]);

        $app = fluentCart();
        $slug = $app->config->get('app.slug');

        Vite::enqueueStyle(
            $slug . '_checkout_confirmation',
            'public/checkout/style/confirmation.scss',
        );

        $content = '';

        ob_start();

        $footerContent = "<div style='padding: 15px; text-align: center; font-size: 16px; color: #2F3448;'>Powered by <a href='https://fluentcart.com' style='color: #017EF3; text-decoration: none;'>". __('FluentCart', 'fluent-cart') ."</a></div>";

        if ((!App::isProActive() || $isEmailFooter == 'yes') && $isReceipt) {
            $parsedContent .= $footerContent;
        }

        $viewData = [
            'order'      => $order,
            'content'    => $parsedContent,
            'is_receipt' => $isReceipt
        ];

        if(Arr::get($viewData,'is_receipt')){
            FrontendView::renderForPrint(Arr::get($viewData,'content'),[
                'wp_head'     => false,
                'wp_footer'    => false,
            ]);
        }else{
            FrontendView::make(__('Thank you', 'fluent-cart'), Arr::get($viewData,'content'));
        }


        $content .= ob_get_clean();
        return $content;
    }

    public function renderNotFoundPage()
    {
        ob_start();
        FrontendView::renderNotFoundPage(
            __('Not found.', 'fluent-cart'),
            __('Sorry, no receipt found.', 'fluent-cart'),
            __("The requested receipt is invalid", 'fluent-cart')
        );
        return ob_get_clean();
    }
}
