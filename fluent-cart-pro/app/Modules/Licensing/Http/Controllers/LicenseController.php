<?php

namespace FluentCartPro\App\Modules\Licensing\Http\Controllers;

use FluentCart\Framework\Http\Controller;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;
use FluentCartPro\App\Modules\Licensing\Services\LicenseManager;
use FluentCartPro\App\Services\PluginManager\FluentLicensing;
use FluentCart\App\Services\Filter\LicenseFilter;

class LicenseController extends Controller
{
    public function index(Request $request): array
    {
//        $activeView = $request->getSafe('active_view', 'sanitize_text_field', 'all');
//        $search = $request->getSafe('search', 'sanitize_text_field', '');
//        $sortBy = $request->getSafe('sort_by', 'sanitize_text_field', 'order_id');
//        $sortType = $request->getSafe('sort_type', 'sanitize_text_field', 'DESC');
//
//        $licenses = License::with([
//                'customer',
//                'product'        => function ($q) {
//                    return $q->select('ID', 'post_title');
//                },
//                'productVariant' => function ($q) {
//                    return $q->select('id', 'variation_title');
//                }
//            ])
//            ->orderBy($sortBy, $sortType)
//            ->status($activeView)
//            ->search($search)
//            ->paginate($request->get('per_page', 10));
//
//
//        foreach ($licenses as $license) {
//            if ($license->expiration_date && $license->expiration_date < DateTime::gmtNow()) {
//                $license->status = 'expired';
//            }
//        }

        $licenses = LicenseFilter::fromRequest($request)->paginate();

        foreach ($licenses as $license) {
            if ($license->expiration_date && $license->expiration_date < DateTime::gmtNow()) {
                $license->status = 'expired';
            }
        }
        return [
            'licenses' => $licenses
        ];
    }

    public function getCustomerLicenses($request, $id): array
    {
        $licences = (new LicenseHelper())->getCustomerLicenses($request, $id);

        return [
            'licenses' => $licences
        ];
    }

    public function getLicense(Request $request, $id): array
    {
        $licenseData = (new LicenseHelper())->getLicenseById($id);

        $license = Arr::get($licenseData, 'license');

        if ($license) {
            $licenseData['prev_orders'] = $license->getPreviousOrders();
        } else {
            $licenseData['prev_orders'] = [];
        }

        return $licenseData;
    }

    public function regenerateLicenseKey(Request $request, $id): array
    {
        $license = License::findOrFail($id);
        $license = $license->regenerateKey();

        return [
            'license' => $license,
            'message' => 'License key regenerated successfully!'
        ];
    }

    public function extendValidity(Request $request, $id)
    {
        $license = License::findOrFail($id);
        $newExpirationDate = $request->getSafe('expiration_date', 'sanitize_text_field');
        $currentExpirationDate = $license->expiration_date;

        // Only check if valid date string (past/future allowed)
        if ($newExpirationDate !== 'lifetime') {
            if (!$newExpirationDate || strtotime($newExpirationDate) === false) {
                return $this->sendError([
                    'message' => __('Invalid expiration date!', 'fluent-cart-pro')
                ], 423);
            }
        }

        $license = $license->extendValidity($newExpirationDate);

        $message = __('License validity extended!', 'fluent-cart-pro');

        if (($currentExpirationDate && $newExpirationDate < $currentExpirationDate) || !$currentExpirationDate) {
            $message = __('License validity reduced!', 'fluent-cart-pro');
        }

        if ($newExpirationDate == 'lifetime') {
            $message = __('Marked license as lifetime!', 'fluent-cart-pro');
        }

        return [
            'license' => $license,
            'message' => $message
        ];
    }

    public function updateStatus(Request $request, $id)
    {

        $newStatus = $request->getSafe('status', 'sanitize_text_field');
        $validStatuses = [
            'disabled',
            'active',
            'expired',
        ];

        if (!in_array($newStatus, $validStatuses)) {
            return $this->sendError([
                'message' => __('Invalid status!', 'fluent-cart-pro')
            ], 423);
        }

        $license = License::findOrFail($id);
        $license = $license->updateLicenseStatus($newStatus);

        return [
            'license' => $license,
            'message' => __('License status has been updated successfully!', 'fluent-cart-pro')
        ];
    }

    public function updateLimit(Request $request, $id): array
    {
        $newLimit = $request->get('limit');

        $license = License::findOrFail($id);

        $license = $license->increaseLimit($newLimit);

        return [
            'license' => $license,
            'message' => __('License limit has been updated successfully!', 'fluent-cart-pro')
        ];
    }

    public function deactivateSite(Request $request, $id)
    {

        $response = (new LicenseManager())
            ->detachSiteByActivationId([
                'license'       => $request->getSafe('id', 'sanitize_text_field'),
                'activation_id' => $request->getSafe('activation_id', 'sanitize_text_field')
            ]);

        if ($response instanceof \WP_Error) {
            return $this->sendError([
                'message' => $response->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Site has been deactivated successfully!', 'fluent-cart-pro')
        ]);
    }

    public function activateSite(Request $request, $id)
    {
        $url = $request->getSafe('url', 'sanitize_url');

        $response = (new LicenseManager())->attachSite(
            [
                'license' => $request->getSafe('id', 'sanitize_text_field'),
                'url'     => $url
            ], 'id');

        if ($response instanceof \WP_Error) {
            return $this->sendError([
                'message' => $response->get_error_message()
            ], 423);
        }

        return $this->sendSuccess([
            'message' => __('Site has been activated successfully!', 'fluent-cart-pro')
        ]);

    }

    public function activateLicense(Request $request)
    {
        $licenseKey = $request->getSafe('license_key', 'sanitize_text_field');
        return FluentLicensing::getInstance()->activate($licenseKey);
    }

    public function deactivateLicense()
    {
        return FluentLicensing::getInstance()->deactivate();
    }

    public function getLicenseDetails()
    {
        return FluentLicensing::getInstance()->getStatus();
    }

    public function deleteLicense($id)
    {
        $license = License::findOrFail($id);

        $license->delete();

        do_action('fluent_cart_sl/license_deleted', [
            'license' => $license
        ]);

        return $this->sendSuccess([
            'message' => __('License deleted successfully!', 'fluent-cart-pro')
        ]);
    }

    public function renewLicense($id)
    {
        //to do
    }
}
