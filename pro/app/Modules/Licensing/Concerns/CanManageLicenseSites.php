<?php

namespace FluentCartPro\App\Modules\Licensing\Concerns;

use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseActivation;
use FluentCartPro\App\Modules\Licensing\Models\LicenseSite;
use FluentCartPro\App\Modules\Licensing\Services\UUID;

trait CanManageLicenseSites
{

    public function attachSite(array $data, $licenseKeyName = null)
    {
        $license = $this->validateLicense($data, $licenseKeyName);
        if ($license instanceof \WP_Error) {
            return $license;
        }

        $variationId = Arr::get($data, 'item_id');

        $this->activateSite(
            $license,
            Arr::only($data, ['url', 'server_version', 'platform_version', 'other']),
            $variationId
        );
    }

    public function activateSite(License $license, array $siteData, $variationId = null)
    {
        $url = $this->parseUrl(Arr::get($siteData, 'url'));

        if (empty($url)) {
            $this->sendErrorResponse($license, 'missing_url');
        }

        $site = $this->getSiteByUrl($url);

        if (empty($site)) {
            $site = new LicenseSite();
            $site->site_url = $url;
            $site->save();
        }

        /**
         * @var LicenseSite $site
         */

        $isLocalSite = $site->isLocalSite();

        if ((!$isLocalSite && !$license->hasActivationLeft())) {
            $this->sendErrorResponse(
                $license,
                'no_activations_left'
            );
        }

        $checksum = UUID::activationHash($license, $site);

        $activationData = [
            'site_id'         => $site->id,
            'license_id'      => $license->id,
            'status'          => 'active',
            'is_local'        => $site->isLocalSite() ? 1 : 0,
            'product_id'      => $license->product_id,
            'variation_id'    => $license->variation_id,
            'activation_hash' => $checksum
        ];

        $activationSearch = LicenseActivation::query()
            ->where('site_id', $site->id)
            ->where('license_id', $license->id);

        if ($activationSearch->exists()) {
            $activation = $activationSearch->first();
        } else {
            $activation = LicenseActivation::query()->create($activationData);
            if (!$isLocalSite) {
                $license->increaseActivationCount();
            }
        }

        do_action('fluent_cart_sl/site_activated', [
            'site'       => $site,
            'license'    => $license,
            'activation' => $activation
        ]);

        $responseData = $this->makeResponseData($license, 'valid', $checksum);
        wp_send_json($responseData, 200);
    }

    public function detachSite(array $data)
    {
        $license = $this->getLicense($data);

        if ($license instanceof \WP_Error) {
            return $license;
        }
        $this->deactivateSite($license, Arr::only($data, ['url', 'server_version', 'platform_version', 'other']));
    }

    public function detachSiteByActivationId(array $data)
    {
        $license = $this->getLicense($data, 'id');
        if ($license instanceof \WP_Error) {
            return $license;
        }

        /**
         * @var License $license
         */

        $activation = LicenseActivation::query()->with('site')->find(Arr::get($data, 'activation_id'));

        if (empty($activation)) {
            return $this->makeError([
                'error' => __('Activation not found', 'fluent-cart-pro')
            ], 'activation_not_found');
        }

        if ($activation->license_id != $license->id) {
            return $this->makeError([
                'error' => __('Activation mismatched', 'fluent-cart-pro')
            ], 'activation_mismatched');
        }

        $siteId = $activation->site_id;

        $site = $activation->site;
        if (!$activation->is_local) {
            $license->decreaseActivationCount();
        }
        $activation->delete();

        if ($siteId) {
            LicenseSite::query()->where('id', $siteId)->delete();
        }

        do_action('fluent_cart_sl/site_license_deactivated', [
            'site'    => $site,
            'license' => $license
        ]);
        return true;
    }

    public function deactivateSite(License $license, array $siteData)
    {
        $url = $this->parseUrl(Arr::get($siteData, 'url'));

        if (empty($url)) {
            $this->sendErrorResponse($license, 'missing_url');
        }

        $site = $this->getSiteByUrl($url);

        if (empty($site)) {
            $this->sendErrorResponse($license, 'invalid_url');
        }

        $activation = $this->getActivation($site->id, $license->id);

        if (empty($activation)) {
            $this->sendErrorResponse($license, 'site_inactive');
        }

        if (!$activation->is_local) {
            $license->decreaseActivationCount();
        }
        $isDeleted = $activation->delete();

        if (!$isDeleted) {
            $this->sendErrorResponse($license, 'failed');
        }

        do_action('fluent_cart_sl/site_license_deactivated', [
            'site'    => $site,
            'license' => $license
        ]);

        $data = $this->makeResponseData($license, 'deactivated', $activation->variation_id);
        wp_send_json($data, 200);
    }

    private function getSiteByUrl(string $url)
    {
        return LicenseSite::query()->where('site_url', $url)->first();
    }

    private function getActivation($siteId, $licenseId)
    {
        return LicenseActivation::query()
            ->where('license_id', $licenseId)
            ->where('site_id', $siteId)
            ->first();
    }
}
