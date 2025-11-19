<?php
namespace FluentCart\App\Http\Controllers;

use FluentCart\App\App;
use FluentCart\App\Services\TemplateService;
use FluentCart\App\Vite;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class TemplateController extends Controller
{
    public function getPrintTemplates(Request $request): \WP_REST_Response
    {
        $availableTemplates = [
            'invoice_template' => __('Invoice Template', 'fluent-cart'),
            'packing_slip'     => __('Packing Slip Template', 'fluent-cart'),
            'delivery_slip'    => __('Delivery Slip Template', 'fluent-cart'),
            'shipping_slip'    => __('Shipping Slip Template', 'fluent-cart'),
            'dispatch_slip'    => __('Dispatch Slip Template', 'fluent-cart'),
        ];
        $templates = [];
        foreach ($availableTemplates as $path => $template) {
            $content = fluent_cart_get_option($path, '');
            if (empty($content)) {
                $content = TemplateService::getInvoicePackingTemplateByPathName($path);
            }
            $templates[] = [
                'key' => $path,
                'title' => $template,
                'content' => $content
            ];
        }

        return $this->sendSuccess([
            'templates' => $templates
        ]);
    }

    public function savePrintTemplates(Request $request): \WP_REST_Response
    {
        $templates = Arr::get($request->all(), 'templates', []);
        foreach ($templates as $template) {
            $key     = sanitize_key(Arr::get($template, 'key'));
            $content = wp_kses_post(Arr::get($template, 'content'));
            fluent_cart_update_option($key, $content, '', 'template');
        }
        return $this->sendSuccess([
            'message' => __('Template saved successfully', 'fluent-cart')
        ]);
    }

}
