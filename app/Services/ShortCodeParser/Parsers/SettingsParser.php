<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Services\Localization\LocalizationManager;

class SettingsParser extends BaseParser
{
    private StoreSettings $storeSettings;


    public function __construct($data)
    {
        $this->storeSettings = new StoreSettings();
        parent::__construct($data);
    }

    protected array $methodMap = [
        'store_logo'     => 'getStoreLogo',
        'store_brand'    => 'getStoreBrandHtml',
        'store_name'     => 'getStoreName',
        'store_address'  => 'getStoreAddress',
        'store_address2' => 'getStoreAddressLine2',
        'store_country'  => 'getStoreCountry',
        'store_state'    => 'getStoreState',
        'store_city'     => 'getStoreCity',
        'store_postcode' => 'getStorePostcode',
    ];

    public function parse($accessor = null, $code = null): ?string
    {
        return $this->get($accessor, $code);
    }

    public function getStoreLogoLink(): ?string
    {
        return $this->storeSettings->get('store_logo.url');
    }

    public function getStoreLogo(): ?string
    {
        $storeLogo = $this->storeSettings->get('store_logo.url');
        $storeName = $this->getStoreName();
        return "<img style='max-height: 50px' src='{$storeLogo}' alt='{$storeName}'>";
    }

    public function getStoreName(): ?string
    {
        return $this->storeSettings->get('store_name');
    }

    public function getStoreBrandHtml(): ?string
    {
        $storeLogoLink = $this->getStoreLogoLink();
        $storeName = $this->getStoreName();

        if (!$storeLogoLink) {
            return $storeName;
        }

        return $this->getStoreLogo();
    }

    public function getStoreAddress()
    {
        return $this->storeSettings->get('store_address1');
    }

    public function getStoreAddressLine2()
    {
        return $this->storeSettings->get('store_address2');
    }

    public function getStoreCountry()
    {
        $country = $this->storeSettings->get('store_country');
        return AddressHelper::getCountryNameByCode($country);
    }

    public function getStoreState()
    {
        $state = $this->storeSettings->get('store_state');
        $country = $this->storeSettings->get('store_country');
        return AddressHelper::getStateNameByCode($state,$country);
    }

    public function getStoreCity()
    {
        return $this->storeSettings->get('store_city');
    }

    public function getStorePostcode()
    {
        return $this->storeSettings->get('store_postcode');
    }
}
