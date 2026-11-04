<?php

namespace FluentCart\App\Modules\Shipping\Carriers\Manual;

use FluentCart\App\Modules\Shipping\Carriers\Core\BaseCarrierSettings;

class ManualCarrierSettings extends BaseCarrierSettings
{
    protected string $metaKey = 'fluent_cart_carrier_settings_manual';

    public function getDefaults(): array
    {
        return [
            'is_active' => 'yes',
        ];
    }
}
