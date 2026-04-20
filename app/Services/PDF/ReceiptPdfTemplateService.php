<?php

namespace FluentCart\App\Services\PDF;

class ReceiptPdfTemplateService
{
    protected $metaKey = 'receipt_pdf_templates';

    /**
     * Get all built-in default templates.
     */
    public function getDefaultTemplates(): array
    {
        return [
            'order_receipt' => [
                'name'         => 'order_receipt',
                'title'        => __('Order Receipt', 'fluent-cart'),
                'description'  => __('Default receipt template for paid orders', 'fluent-cart'),
                'is_default'   => true,
                'pdf_settings' => [
                    [
                        'active'        => 'yes',
                        'title'         => __('Order Receipt', 'fluent-cart'),
                        'preview_image' => '',
                        'pdf_structure' => DefaultPdfStructures::getDefaultReceiptStructure(),
                    ]
                ]
            ],
            'renewal_receipt' => [
                'name'         => 'renewal_receipt',
                'title'        => __('Renewal Receipt', 'fluent-cart'),
                'description'  => __('Receipt template for subscription renewal payments', 'fluent-cart'),
                'is_default'   => true,
                'pdf_settings' => [
                    [
                        'active'        => 'yes',
                        'title'         => __('Renewal Receipt', 'fluent-cart'),
                        'preview_image' => '',
                        'pdf_structure' => DefaultPdfStructures::getDefaultRenewalReceiptStructure(),
                    ]
                ]
            ],
            'refund_notice' => [
                'name'         => 'refund_notice',
                'title'        => __('Refund Notice', 'fluent-cart'),
                'description'  => __('Notice template for refund confirmations', 'fluent-cart'),
                'is_default'   => true,
                'pdf_settings' => [
                    [
                        'active'        => 'yes',
                        'title'         => __('Refund Notice', 'fluent-cart'),
                        'preview_image' => '',
                        'pdf_structure' => DefaultPdfStructures::getDefaultRefundNoticeStructure(),
                    ]
                ]
            ],
            'proforma_invoice' => [
                'name'         => 'proforma_invoice',
                'title'        => __('Invoice', 'fluent-cart'),
                'description'  => __('Invoice template for offline/pending orders', 'fluent-cart'),
                'is_default'   => true,
                'pdf_settings' => [
                    [
                        'active'        => 'yes',
                        'title'         => __('Invoice', 'fluent-cart'),
                        'preview_image' => '',
                        'pdf_structure' => DefaultPdfStructures::getDefaultProformaInvoiceStructure(),
                    ]
                ]
            ],
        ];
    }

    /**
     * Get saved templates, migrating from old format if needed.
     */
    public function getTemplates(): array
    {
        $saved = fluent_cart_get_option($this->metaKey);

        if (!$saved || !is_array($saved)) {
            $defaults = $this->getDefaultTemplates();
            fluent_cart_update_option($this->metaKey, $defaults);
            return $defaults;
        }

        // Migrate old single-template format ('default' key without 'is_default')
        if (isset($saved['default']) && !isset($saved['default']['is_default'])) {
            $migrated = $this->migrateOldFormat($saved);
            fluent_cart_update_option($this->metaKey, $migrated);
            return $migrated;
        }

        // Ensure all built-in templates exist (in case new ones were added)
        $defaults = $this->getDefaultTemplates();
        $needsUpdate = false;
        foreach ($defaults as $key => $defaultTemplate) {
            if (!isset($saved[$key])) {
                $saved[$key] = $defaultTemplate;
                $needsUpdate = true;
            }
        }

        if ($needsUpdate) {
            fluent_cart_update_option($this->metaKey, $saved);
        }

        return $saved;
    }

    /**
     * Migrate from old single 'default' template to new multi-template format.
     */
    private function migrateOldFormat(array $saved): array
    {
        $defaults = $this->getDefaultTemplates();

        // Rename 'default' to 'order_receipt', preserving user's customizations
        if (isset($saved['default'])) {
            $oldTemplate = $saved['default'];
            $defaults['order_receipt']['pdf_settings'] = $oldTemplate['pdf_settings'] ?? $defaults['order_receipt']['pdf_settings'];
        }

        // Preserve any custom templates that might exist
        foreach ($saved as $key => $template) {
            if ($key !== 'default' && !isset($defaults[$key])) {
                $defaults[$key] = $template;
            }
        }

        return $defaults;
    }

    /**
     * Update a template by key.
     */
    public function updateTemplate(string $key, array $template): bool
    {
        $templates = $this->getTemplates();
        if (!isset($templates[$key])) {
            return false;
        }

        $templates[$key] = array_merge($templates[$key], $template);

        return (bool) fluent_cart_update_option($this->metaKey, $templates);
    }

    /**
     * Save the PDF structure for a specific template.
     */
    public function setTemplateProperties(array $data, string $templateId = 'order_receipt'): bool
    {
        $templates = $this->getTemplates();

        if (!isset($templates[$templateId])) {
            return false;
        }

        $templates[$templateId]['pdf_settings'][0]['pdf_structure'] = $data;

        return (bool) fluent_cart_update_option($this->metaKey, $templates);
    }

    /**
     * Create a new custom template.
     *
     * @return string The generated template slug
     */
    public function createTemplate(string $title): string
    {
        $templates = $this->getTemplates();

        $slug = 'custom_' . time() . '_' . wp_rand(1000, 9999);
        $templates[$slug] = [
            'name'         => $slug,
            'title'        => $title,
            'description'  => '',
            'is_default'   => false,
            'pdf_settings' => [
                [
                    'active'        => 'yes',
                    'title'         => $title,
                    'preview_image' => '',
                    'pdf_structure' => DefaultPdfStructures::getDefaultReceiptStructure(),
                ]
            ]
        ];

        fluent_cart_update_option($this->metaKey, $templates);

        return $slug;
    }

    /**
     * Delete a custom template. Built-in templates cannot be deleted.
     */
    public function deleteTemplate(string $key): bool
    {
        $templates = $this->getTemplates();

        if (!isset($templates[$key])) {
            return false;
        }

        if (!empty($templates[$key]['is_default'])) {
            return false;
        }

        unset($templates[$key]);

        return (bool) fluent_cart_update_option($this->metaKey, $templates);
    }

    /**
     * Get the factory default template structures (ignoring saved data).
     */
    public function getFactoryDefault(): array
    {
        return $this->getDefaultTemplates();
    }

    /**
     * Get the current PDF structure for a specific template.
     */
    public function getPdfStructure(string $templateId = 'order_receipt'): array
    {
        $templates = $this->getTemplates();

        if (isset($templates[$templateId])) {
            return $templates[$templateId]['pdf_settings'][0]['pdf_structure'] ?? DefaultPdfStructures::getDefaultReceiptStructure();
        }

        // Fallback to order_receipt if requested template doesn't exist
        if (isset($templates['order_receipt'])) {
            return $templates['order_receipt']['pdf_settings'][0]['pdf_structure'] ?? DefaultPdfStructures::getDefaultReceiptStructure();
        }

        return DefaultPdfStructures::getDefaultReceiptStructure();
    }

    /**
     * Get a simplified list of templates for dropdown selection.
     */
    public function getTemplateList(): array
    {
        $templates = $this->getTemplates();
        $list = [];

        foreach ($templates as $key => $template) {
            $list[] = [
                'id'    => $key,
                'title' => $template['title'] ?? $key,
            ];
        }

        return $list;
    }
}
