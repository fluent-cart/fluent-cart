<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Services\Filter\TaxFilter;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Http\Request\Request;

class TaxController extends Controller
{
    public function index(Request $request)
    {
        $taxes = TaxFilter::fromRequest($request)->paginate();
        $this->enrichOrphanedRateRows($taxes->getCollection());
        return $this->sendSuccess(['taxes' => $taxes]);
    }

    /**
     * For rows where the tax_rate FK has no matching DB row (EU home/specific-mode virtual
     * rates stored as tax_rate_id=0, or rates deleted after order placement), the label was
     * never written to meta at order time. Attempt to recover it from the EU VAT registration
     * for that country — one extra DB query per unique country, run only when needed.
     */
    private function enrichOrphanedRateRows($items)
    {
        $needsLookup = [];
        foreach ($items as $item) {
            if ($item->tax_rate !== null) {
                continue;
            }
            $meta    = $item->meta;
            $country = isset($meta['tax_country']) ? (string) $meta['tax_country'] : '';
            if ($country && empty($meta['label'])) {
                $needsLookup[$country] = true;
            }
        }

        if (empty($needsLookup)) {
            return;
        }

        $taxManager    = TaxManager::getInstance();
        $registrations = [];
        foreach (array_keys($needsLookup) as $country) {
            $reg = $taxManager->getEuVatRegistration($country);
            if ($reg) {
                $registrations[$country] = $reg;
            }
        }

        if (empty($registrations)) {
            return;
        }

        foreach ($items as $item) {
            if ($item->tax_rate !== null) {
                continue;
            }
            $meta    = $item->meta;
            $country = isset($meta['tax_country']) ? (string) $meta['tax_country'] : '';
            if ($country && empty($meta['label']) && isset($registrations[$country])) {
                $meta['label'] = isset($registrations[$country]['tax_label'])
                    ? (string) $registrations[$country]['tax_label']
                    : '';
                $item->meta = $meta;
            }
        }
    }

    public function markAsFiled(Request $request)
    {
        $idsToMark = Arr::get($request->getSafe(['ids.*' => 'intval']), 'ids', []);

        if (empty($idsToMark)) {
            return $this->sendError([
                'message' => __('No IDs provided to mark!', 'fluent-cart'),
            ], 400);
        }

        $result = OrderTaxRate::whereIn('id', $idsToMark)
            ->whereNull('filed_at')
            ->update([
                'filed_at' => DateTime::gmtNow(),
            ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->sendSuccess(['message' => __('Taxes marked as filed successfully', 'fluent-cart')]);
    }
}
