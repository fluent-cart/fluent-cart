<?php

namespace FluentCart\App\Services;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Str;

class URL
{
    /**
     * Append query parameters to a URI, ensuring proper formatting
     *
     * @param string $uri Base URI to append parameters to
     * @param array $params Query parameters to append
     * @return string URI with appended query parameters
     */
    public static function appendQueryParams(string $uri, $params = []): string
    {
        // Ensure the URI ends with '/' if no query string and no trailing slash
        if (!Str::endsWith($uri, '/') && !Str::contains($uri, '?')) {
            $uri .= '/';
        }

        return add_query_arg($params, $uri);
    }


    public static function getFrontEndUrl($page = '', $params = []): string
    {
        $baseUrl = site_url('/');

        $baseParams = [
            'fluent-cart' => $page
        ];

        // Merge base and custom params
        $allParams = is_array($params)
            ? array_merge($baseParams, $params)
            : $baseParams;

        return self::appendQueryParams($baseUrl, $allParams);
    }

    public static function getApiUrl($path = '', $params = null): string
    {
        $url = Str::of($path)->startsWith('/') ?
            Helper::getRestInfo()['url'] . $path :
            Helper::getRestInfo()['url'] . '/' . $path;

        if (is_array($params)) {
            return self::appendQueryParams($url, $params);
        }
        return $url;
    }


    public static function getDashboardUrl(string $path, $params = null): string
    {
        $url = admin_url('admin.php?page=fluent-cart#/');

        if (is_array($params)) {
            return $url . self::appendQueryParams($path, $params);
        }
        return $url . $path;

    }

    public static function getCustomerDashboardUrl($path): string
    {
        $url = (new StoreSettings())->getCustomerProfilePage();
        $url = rtrim($url, '/');

        if ($path) {
            // add the extension with a leading slash
            $url .= '/' . rtrim($path, '/');
        }

        return $url;
    }

    public static function getCustomerOrderUrl($uuid): string
    {
        $storeSettings = new StoreSettings();
        $customerPage = $storeSettings->getCustomerProfilePage();
        if ($customerPage) {
            return site_url("/{$customerPage}/order/{$uuid}");
        } else {
            return self::getFrontEndUrl();
        }
    }
}