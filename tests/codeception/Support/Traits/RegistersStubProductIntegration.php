<?php

namespace Tests\Support\Traits;

/**
 * Registers stub providers against the FluentCart integration filters so that
 * functional tests covering the product-integrations REST surface can exercise
 * `getFeeds` / `getProductIntegrationSettings` / `saveProductIntegration`
 * without booting a real provider (MailChimp, FluentCRM, etc.).
 *
 * The controller funnels every product-scoped integration through these
 * filters:
 *   - fluent_cart/integration/order_integrations          (provider catalog)
 *   - fluent_cart/integration/get_integration_settings_fields_<provider>
 *
 * Cest methods that need a registered provider call
 * registerStubProductIntegration() in their setup, then unregisterStubProductIntegrations()
 * in `_after` so filters do not leak into other Cests within the same PHP process.
 */
trait RegistersStubProductIntegration
{
    /** @var array<int, array{0:string, 1:callable, 2:int}> */
    private array $stubIntegrationFilters = [];

    protected function registerStubProductIntegration(
        string $key = 'stub_provider',
        array $info = [],
        ?array $settingsFields = null
    ): string {
        $info = array_merge([
            'priority'                => 10,
            'title'                   => 'Stub Provider',
            'description'             => 'Functional test stub integration',
            'category'                => 'crm',
            'disable_global_settings' => false,
            'config_url'              => '',
            'logo'                    => '',
            'enabled'                 => true,
            'scopes'                  => ['product'],
            'installable'             => '',
            'delay_on_product_action' => false,
            'delay_on_global_action'  => false,
        ], $info);

        $catalogHook = function ($addons) use ($key, $info) {
            $addons = is_array($addons) ? $addons : [];
            $addons[$key] = $info;
            return $addons;
        };
        $catalogFilter = 'fluent_cart/integration/order_integrations';
        add_filter($catalogFilter, $catalogHook, 10);
        $this->stubIntegrationFilters[] = [$catalogFilter, $catalogHook, 10];

        $fieldsPayload = $settingsFields !== null ? $settingsFields : [
            'fields' => [
                [
                    'key'      => 'name',
                    'label'    => 'Feed Name',
                    'required' => true,
                ],
                [
                    'key'      => 'list_id',
                    'label'    => 'List ID',
                    'required' => false,
                ],
            ],
        ];

        $fieldsHook = function () use ($fieldsPayload) {
            return $fieldsPayload;
        };
        $fieldsFilter = 'fluent_cart/integration/get_integration_settings_fields_' . $key;
        add_filter($fieldsFilter, $fieldsHook, 10);
        $this->stubIntegrationFilters[] = [$fieldsFilter, $fieldsHook, 10];

        return $key;
    }

    protected function unregisterStubProductIntegrations(): void
    {
        foreach ($this->stubIntegrationFilters as [$hook, $callback, $priority]) {
            remove_filter($hook, $callback, $priority);
        }
        $this->stubIntegrationFilters = [];
    }
}
