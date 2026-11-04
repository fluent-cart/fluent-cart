<?php

namespace Tests\Support\Shipping;

use FluentCart\App\Modules\Shipping\Carriers\Core\BaseCarrierSettings;

/**
 * Manual carrier settings a test can switch on or off without touching the
 * stored option (and its per-request cache).
 */
class ManualQuoteTestSettings extends BaseCarrierSettings
{
    protected string $metaKey = 'fluent_cart_carrier_settings_manual_test';

    private string $active;

    public function __construct(bool $active = true)
    {
        $this->active = $active ? 'yes' : 'no';
    }

    public function getDefaults(): array
    {
        return ['is_active' => $this->active];
    }

    public function get($key = '')
    {
        if ($key === '') {
            return ['is_active' => $this->active];
        }

        return $key === 'is_active' ? $this->active : null;
    }
}
