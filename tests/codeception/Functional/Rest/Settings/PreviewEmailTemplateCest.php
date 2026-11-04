<?php

namespace Tests\Functional\Rest\Settings;

use FluentCart\Api\StoreSettings;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

class PreviewEmailTemplateCest
{
    public function adminSeesTheStoreTaxIdInAnOrderEmailPreview(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new StoreSettings())->set('seller_tax_id', 'TAX-PREVIEW-7781');
        StoreSettings::clearCache();

        [$status, $body] = $I->post('/fluent-cart/v2/email-notification/preview-default-template', [
            'template' => 'order.paid.customer',
        ]);

        Assert::assertSame(200, $status, 'The order email preview must render: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'TAX-PREVIEW-7781',
            (string) ($body['data']['content'] ?? ''),
            'The preview must show the seller tax ID from store settings, as a new order would.'
        );
    }
}
