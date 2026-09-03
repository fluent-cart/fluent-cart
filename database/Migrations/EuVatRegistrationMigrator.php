<?php

namespace FluentCart\Database\Migrations;

use FluentCart\App\Models\Meta;
use FluentCart\Framework\Support\Arr;

class EuVatRegistrationMigrator
{
    public static function migrate()
    {
        $settings      = get_option('fluent_cart_tax_configuration_settings', []);
        $registrations = Arr::get($settings, 'eu_vat_settings.country_registrations', []);

        if (empty($registrations)) {
            return;
        }

        foreach ($registrations as $reg) {
            $country = strtoupper($reg['country'] ?? '');
            if (!$country) {
                continue;
            }

            Meta::query()->updateOrCreate(
                ['object_type' => 'eu_vat_registration', 'meta_key' => $country],
                ['meta_value' => $reg, 'object_id' => 0]
            );
        }

        unset($settings['eu_vat_settings']['country_registrations']);
        update_option('fluent_cart_tax_configuration_settings', $settings, true);
    }
}
