<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Services\Filter\TaxFilter;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Http\Request\Request;

class TaxController extends Controller
{
    public function index(Request $request)
    {
        return $this->sendSuccess([
            'taxes' => TaxFilter::fromRequest($request)->paginate(),
        ]);
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
