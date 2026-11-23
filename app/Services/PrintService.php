<?php

namespace FluentCart\App\Services;

use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class PrintService
{
    public static function invoice($order)
    {
        return static::print('invoice_template', $order);
    }

    public static function packingSlip($order)
    {
        return static::print('packing_slip', $order);
    }

    public static function deliverySlip($order)
    {
        return static::print('delivery_slip', $order);
    }

    public static function shippingSlip($order)
    {
        return static::print('shipping_slip', $order);
    }

    public static function dispatchSlip($order)
    {
        return static::print('dispatch_slip', $order);
    }

    public static function print($key, $orderId)
    {
        $template = Meta::query()->where('meta_key', $key)->first();
        if ($template) {
            $template = $template->meta_value;
        } else {
            $template = TemplateService::getInvoicePackingTemplateByPathName($key);
        }
        // Templates call Order model methods, so pass the model, not OrderResource::view()'s array.
        // Print links use the order uuid, like the receipt links.
        $order = is_scalar($orderId)
            ? Order::query()->where('uuid', sanitize_text_field((string) $orderId))->first()
            : null;
        if (!$order) {
            status_header(404);
            FrontendView::renderNotFoundPage(
                __('Order not found', 'fluent-cart'),
                __('Order not found', 'fluent-cart'),
                __('This print link is not valid, or the order no longer exists.', 'fluent-cart')
            );
            return false;
        }

        $renderedTemplate = ShortcodeTemplateBuilder::makeHtml($template ?? '', [
            'order' => $order
        ]);

        if (has_action('fluent_pdf_download')) {
            do_action('fluent_pdf_download', [
                'body' => $renderedTemplate,
            ],
                Str::of($key)->headline() . '-InvoiceNO-' . Arr::get($order, 'order.invoice_no')
            );
            die();
        }
        return FrontendView::renderForPrint($renderedTemplate);
    }

}
