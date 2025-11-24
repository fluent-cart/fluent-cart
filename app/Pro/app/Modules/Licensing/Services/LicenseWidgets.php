<?php

namespace FluentCartPro\App\Modules\Licensing\Services;

class LicenseWidgets
{
    public function register()
    {
        add_filter('fluent_cart/widgets/single_order', [$this, 'singleOrder'], 10, 2);
    }
    public function singleOrder($oldWidgets, $order)
    {
        $licenses = \FluentCartPro\App\Modules\Licensing\Models\License::query()
            ->where('order_id', $order->id)
            ->with(['productVariant' => function ($query) {
                $query->with('product', function ($query) {
                    $query->select('ID', 'post_title');
                });
            }])->get();

        if ($licenses->isEmpty()) {
            return $oldWidgets;
        }

        $widgets = [
            [
                'title' => 'Licenses',
                'sub_title' => 'Order licenses',
                'type' => 'html',
                'content' => '<span class="fluent_cart_order_licenses">'. $this->getContent($licenses) .'</span>'
            ]
        ];

        return array_merge($oldWidgets, $widgets);
    }

    public function getContent($licenses): string
    {
        $content = '<div>';
        foreach ($licenses as $license) {
            $link = esc_url(admin_url('admin.php?page=fluent-cart#/licenses/' . $license->id . '/view'));
            $content .= '<b><p style="margin:0 0 2px 0">' . esc_html($license->product->post_title) .'( ' . esc_html($license->productVariant->variation_title) . ' )</p></b>';
            $content .= '<p style="display:flex;margin:0;align-items:center;">
                        <code style="word-wrap: break-word;">' . esc_html($license->license_key) . '</code>
                        <a style="margin-left:4px;" href="'.$link.'">View</a>
                        </p></div><br/>';
        }
        return $content;
    }

}