<?php

namespace FluentCart\App\Modules\Shipping\Carriers\Http\Controllers;

use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use FluentCart\App\Modules\Shipping\Services\CarrierSettingsService;
use FluentCart\Framework\Http\Request\Request;

class CarrierController extends Controller
{
    public function index(Request $request)
    {
        return $this->sendSuccess([
            'carriers' => CarrierManager::getInstance()->getAllMeta(),
        ]);
    }

    /**
     * Registered carriers — slug, name and enablement only — for the Add Shipment
     * dropdown. Disabled carriers are included: the enable toggle governs what
     * checkout offers, not what an admin may record after the fact.
     *
     * Split from index() because that one exposes settings blobs and is gated on
     * `store/sensitive`, which an order manager does not have. This carries
     * nothing sensitive, so it can be gated on the order permissions that
     * creating a shipment already requires.
     */
    public function dispatchOptions(Request $request)
    {
        return $this->sendSuccess([
            'carriers' => CarrierManager::getInstance()->getDispatchOptions(),
        ]);
    }

    public function updateSettings(Request $request, $slug)
    {
        $slug    = sanitize_key($slug);
        $carrier = CarrierManager::getInstance()->get($slug);

        if (!$carrier) {
            return $this->sendError([
                'message' => __('Carrier not found.', 'fluent-cart'),
            ], 404);
        }

        $settings = CarrierSettingsService::save($carrier, $request->all());

        if (is_wp_error($settings)) {
            return $this->sendError([
                'message' => $settings->get_error_message(),
            ], 422);
        }

        // The whole carrier, rebuilt after the save: fields whose options come
        // from stored data (e.g. connected accounts) refresh without a reload.
        return $this->sendSuccess([
            'message'  => __('Carrier settings saved successfully.', 'fluent-cart'),
            'settings' => $settings,
            'carrier'  => CarrierManager::getInstance()->describeCarrier($slug),
        ]);
    }

    public function runAction(Request $request, $slug, $action)
    {
        $slug    = sanitize_key($slug);
        $carrier = CarrierManager::getInstance()->get($slug);

        if (!$carrier || !method_exists($carrier, 'runAction')) {
            return $this->sendError([
                'message' => __('Carrier not found.', 'fluent-cart'),
            ], 404);
        }

        $result = $carrier->runAction(sanitize_key($action), $request->all());

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message(),
            ], 422);
        }

        // An action can fail at the carrier and still change what the page shows, so the carrier always comes back.
        return $this->sendSuccess([
            'message' => isset($result['message']) ? (string) $result['message'] : '',
            'ok'      => !isset($result['ok']) || (bool) $result['ok'],
            'carrier' => CarrierManager::getInstance()->describeCarrier($slug),
        ]);
    }
}
