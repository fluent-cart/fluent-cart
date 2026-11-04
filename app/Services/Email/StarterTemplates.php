<?php

namespace FluentCart\App\Services\Email;

use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

/**
 * Email starter templates that ship with core. The picker lives in Pro's
 * block editor, which reads them through fluent_cart_pro/email/starter_templates.
 */
class StarterTemplates
{
    /**
     * @param string $notificationKey e.g. order_shipment_created_customer
     * @return array[] each ['id', 'name', 'description', 'thumbnail', 'content']
     */
    public static function getForNotification(string $notificationKey): array
    {
        $mapping = [
            'order_shipment_created_customer' => ['shipment_update/basic'],
        ];

        if (empty($mapping[$notificationKey])) {
            return [];
        }

        $definitions = self::getDefinitions();
        $templates = [];

        foreach ($mapping[$notificationKey] as $templateId) {
            if (!isset($definitions[$templateId])) {
                continue;
            }

            $content = self::getContent($templateId);
            if ($content === '') {
                continue;
            }

            $templates[] = [
                'id'          => $templateId,
                'name'        => $definitions[$templateId]['name'],
                'description' => $definitions[$templateId]['description'],
                'thumbnail'   => Vite::getAssetUrl('images/email-template/email-banner.png'),
                'content'     => $content,
            ];
        }

        return $templates;
    }

    /**
     * Listener for Pro's fluent_cart_pro/email/starter_templates filter.
     * Core templates go first: Pro auto-applies the first non-blank one on an empty editor.
     *
     * @param array $templates
     * @param array $context ['template' => notification key]
     * @return array
     */
    public static function addToPicker($templates, $context = []): array
    {
        $templates = is_array($templates) ? $templates : [];
        $existingIds = array_column($templates, 'id');

        $coreTemplates = array_filter(
            static::getForNotification((string) Arr::get($context, 'template', '')),
            function ($template) use ($existingIds) {
                return !in_array($template['id'], $existingIds, true);
            }
        );

        return array_merge(array_values($coreTemplates), $templates);
    }

    private static function getDefinitions(): array
    {
        return [
            'shipment_update/basic' => [
                'name'        => __('Shipment Update', 'fluent-cart'),
                'description' => __('Carrier, tracking link and the items in this shipment, with addresses and an order link.', 'fluent-cart'),
            ],
        ];
    }

    private static function getContent(string $templateId): string
    {
        $file = FLUENTCART_PLUGIN_PATH . 'app/Views/emails/starter_templates/' . $templateId . '.php';

        if (!is_readable($file)) {
            return '';
        }

        $content = include $file;

        return is_string($content) ? $content : '';
    }
}
