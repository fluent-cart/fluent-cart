<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\App\Services\Renderer\CustomerDashboardButtonRenderer;

class CustomerDashboardButtonShortcode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_cart_customer_dashboard_button';

    protected function getStyles(): array
    {
        return [
            'public/customer-dashboard-button/customer-dashboard-button.scss',
        ];
    }

    public function render(?array $viewData = null)
    {
        $data = $viewData ?? $this->shortCodeAttributes ?? [];
        $data['is_shortcode'] = true;

        $renderer = new CustomerDashboardButtonRenderer();
        $renderer->render($data);
    }
}