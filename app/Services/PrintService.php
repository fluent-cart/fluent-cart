<?php

namespace FluentCart\App\Services;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\Meta;
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
        $order = OrderResource::view($orderId)['order'];

        $renderedTemplate = ShortcodeTemplateBuilder::make($template ?? '', [
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
