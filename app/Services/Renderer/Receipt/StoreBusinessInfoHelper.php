<?php

namespace FluentCart\App\Services\Renderer\Receipt;

use FluentCart\App\Models\Order;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\Framework\Support\Arr;

class StoreBusinessInfoHelper
{
    /**
     * Seller ID lines (registration, tax, VAT) for receipts, the thank-you
     * page and order emails. Plain text; callers escape and style them.
     *
     * @return string[]
     */
    public static function getIdLines(Order $order): array
    {
        $info = $order->getStoreBusinessInfo();
        $labels = TaxModule::getBusinessIdLabels($info['store_country']);
        $lines = [];

        if ($info['legal_registration_id'] !== '') {
            $lines[] = sprintf($labels['legal_registration_id'], $info['legal_registration_id']);
        }

        if ($info['seller_tax_id'] !== '') {
            $lines[] = sprintf($labels['seller_tax_id'], $info['seller_tax_id']);
        }

        // Callers already print the tax-rate VAT, the registration for the order's tax country.
        $order->loadMissing(['orderTaxRates']);
        $taxRate = $order->getPrimaryOrderTaxRate();
        $taxRateVat = (string) Arr::get($taxRate->meta ?? [], 'store_vat_number', '');

        if ($taxRateVat === '' && $info['seller_vat_id'] !== '') {
            $lines[] = sprintf($labels['seller_vat_id'], $info['seller_vat_id']);
        }

        return $lines;
    }

    public static function getCompanyName(Order $order, string $fallback = ''): string
    {
        $companyName = $order->getStoreBusinessInfo()['company_name'];

        return $companyName !== '' ? $companyName : $fallback;
    }
}
