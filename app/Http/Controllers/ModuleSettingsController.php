<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Sanitizer\Sanitizer;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ModuleSettingsController extends Controller
{
    public function getSettings(): \WP_REST_Response
    {
        $fields = ModuleSettings::fileds();
        $values = ModuleSettings::getAllSettings();

        return $this->sendSuccess([
            'fields'   => [
                'modules_settings' => [
                    'title'           => __('Features & addon', 'fluent-cart'),
                    'type'            => 'section',
                    'class'           => 'no-padding',
                    'disable_nesting' => true,
                    'columns'         => [
                        'default' => 1,
                        'md'      => 1
                    ],
                    'schema'          => $fields
                ]
            ],
            'settings' => $values
        ]);
    }

    public function saveSettings(Request $request)
    {
        $prevSettings = ModuleSettings::getAllSettings(false);

        $data = $request->only(
            ModuleSettings::validKeys()
        );

        $data = Sanitizer::sanitize($data);

        ModuleSettings::saveSettings($data);

        foreach ($data as $moduleKey => $moduleData) {
            $prevStatus = Arr::get($prevSettings, $moduleKey . '.active', 'no');
            $newStatus = Arr::get($moduleData, 'active', 'no');
            if ($newStatus === $prevStatus) {
                continue;
            }

            if ($prevStatus === 'yes' && $newStatus === 'no') {
                // Module deactivated
                do_action('fluent_cart/module/deactivated/' . $moduleKey, $moduleData, $prevSettings[$moduleKey]);
            } elseif ($prevStatus === 'no' && $newStatus === 'yes') {
                // Module activated
                do_action('fluent_cart/module/activated/' . $moduleKey, $moduleData, $prevSettings[$moduleKey]);
            }
        }

        return $this->sendSuccess([
            'message' => __('Settings saved successfully', 'fluent-cart')
        ]);
    }
}
