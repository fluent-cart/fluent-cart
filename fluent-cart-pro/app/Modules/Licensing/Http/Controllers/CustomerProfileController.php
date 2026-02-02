<?php

namespace FluentCartPro\App\Modules\Licensing\Http\Controllers;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Framework\Http\Controller;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseActivation;
use FluentCartPro\App\Modules\Licensing\Models\LicenseSite;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;

class CustomerProfileController extends Controller
{
    public function getLicenses(Request $request)
    {
        $customer = CustomerResource::getCurrentCustomer();
        if (!$customer) {
            return $this->sendSuccess([
                'message'  => __('Unable to find licenses', 'fluent-cart-pro'),
                'licenses' => [
                    'data'  => [],
                    'total' => 0,
                ]
            ]);
        }

        // modify status if expiration date is in past
        $licenses = License::query()
            ->with(['productVariant', 'product'])
            ->withCount('activations')
            ->where('customer_id', $customer->id)
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 10), ['*'], 'page', $request->get('page', 1));

        $formattedLicenses = $licenses->map(function ($license) {
            return LicenseHelper::formatLicense($license);
        });

        return [
            'licenses' => [
                'data'         => $formattedLicenses,
                'total'        => $licenses->total(),
                'per_page'     => $licenses->perPage(),
                'current_page' => $licenses->currentPage(),
                'last_page'    => $licenses->lastPage(),
            ]
        ];
    }

    public function getLicenseDetails(Request $request, $licenseKey)
    {
        $customer = CustomerResource::getCurrentCustomer();

        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart-pro')
            ]);
        }

        $license = License::query()
            ->where('license_key', $licenseKey)
            ->where('customer_id', $customer->id)
            ->with(['productVariant'])
            ->first();


        if (!$license) {
            return $this->sendError([
                'message' => 'License not found',
            ], 422);
        }


        $status = $license->getHumanReadableStatus();


        $formattedLicense = [
            'license_key'      => $license->license_key,
            'status'           => $status,
            'expiration_date'  => $license->expiration_date,
            'variation_id'     => $license->variation_id,
            'activation_count' => $license->activation_count,
            'limit'            => $license->limit,
            'product_id'       => $license->product_id,
            'created_at'       => $license->created_at->format('Y-m-d H:i:s'),
            'title'            => $license->product ? $license->product->post_title : 'Unknown Product',
            'subtitle'         => $license->productVariant ? $license->productVariant->variation_title : '',
            'renewal_url'      => $license->getRenewalUrl(),
            'has_upgrades'     => $license->hasUpgrades(),
            'order'            => [
                'uuid' => $license->order ? $license->order->uuid : ''
            ]
        ];

        $hooksContents = apply_filters('fluent_cart/customer/license_details_section_parts', [
            'before_summary'     => '',
            'after_summary'      => '',
            'end_of_details'     => '',
            'additional_actions' => ''
        ], [
            'license'       => $license,
            'formattedData' => $formattedLicense
        ]);

        return $this->sendSuccess([
            'license'       => $formattedLicense,
            'section_parts' => $hooksContents
        ]);

    }

    public function getActivations(Request $request, $licenseKey)
    {
        $customer = CustomerResource::getCurrentCustomer();

        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart-pro')
            ]);
        }

        $license = License::query()
            ->where('license_key', $licenseKey)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$license) {
            return $this->sendError([
                'message' => 'License not found',
            ], 422);
        }

        $activations = LicenseActivation::query()
            ->where('license_id', $license->id)
            ->with(['site'])
            ->orderBy('id', 'DESC')
            ->get();

        $formattedActivations = $activations->map(function ($activation) {
            return [
                'site_url'   => $activation->site ? $activation->site->site_url : '',
                'is_local'   => $activation->is_local,
                'status'     => $activation->status,
                'created_at' => $activation->created_at->format('Y-m-d H:i:s'),
            ];
        });

        return [
            'activations' => $formattedActivations
        ];
    }

    public function deactivateSite(Request $request, $licenseKey)
    {
        $customer = CustomerResource::getCurrentCustomer();

        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart-pro')
            ]);
        }

        $license = License::query()
            ->where('license_key', $licenseKey)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$license) {
            return $this->sendError([
                'message' => 'License not found',
            ], 422);
        }

        $site = LicenseSite::query()->where('site_url', $request->get('site_url'))
            ->whereHas('activations', function ($query) use ($license) {
                $query->where('license_id', $license->id);
            })
            ->first();

        if ($site) {
            $activation = LicenseActivation::query()
                ->where('license_id', $license->id)
                ->where('site_id', $site->id)
                ->first();

            if ($activation) {
                $activation->delete();
                $license->recountActivations();

                do_action('fluent_cart_sl/site_license_deactivated', [
                    'site'    => $site,
                    'license' => $license
                ]);

                return $this->sendSuccess([
                    'message' => __('Site deactivated successfully', 'fluent-cart-pro')
                ]);
            }
        }

        return $this->sendError([
            'message' => __('Site not found or not activated for this license', 'fluent-cart-pro')
        ], 422);
    }

}
