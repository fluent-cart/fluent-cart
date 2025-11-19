<?php

namespace FluentCart\Framework\Http;

use FluentCart\Framework\Foundation\App;

class UrlGenerator
{
    /**
     * The application instance.
     *
     * @var \FluentCart\Framework\Foundation\App|null
     */
    protected $app = null;

    /**
     * Create a new URL Generator instance.
     *
     * @param  \FluentCart\Framework\Foundation\App|null  $app
     */
    public function __construct($app = null)
    {
        $this->app = $app ?: App::getInstance();
    }

    /**
     * Generate a full REST URL from a named route.
     *
     * @param  string  $nameOrPath   The name of the route or path.
     * @param  array   $params Optional parameters to fill in the placeholders.
     * @param  array   $query  Optional query parameters to append to the URL.
     * @return string|null     The full REST URL or null if the route doesn't exist.
     */
    public function route($nameOrPath, $params = [], $query = [])
    {
        // @phpstan-ignore-next-line
        $route = $this->app->router->getByName($nameOrPath);

        if (!$route) {
            if (!str_contains($nameOrPath, '/')) {
                return;
            }
            $path = '/' . trim($nameOrPath, '/');
        } else {
            $path = $this->buildPath($route->uri(), $params);
        }

        $fullUrl = $this->buildFullUrl($path);

        return $this->appendQueryString($fullUrl, $query);
    }

    /**
     * Build the full URL including base REST path,
     * namespace, version, and route path.
     *
     * @param  string  $path
     * @return string
     */
    protected function buildFullUrl($path)
    {
        $restUrl = $this->buildRestBaseUrl();

        $namespaceSegment = $this->buildNamespaceSegment();

        return $restUrl . $namespaceSegment . $path;
    }

    /**
     * Get the base REST URL (e.g., https://wpfluent.org/wp-json).
     *
     * @return string
     */
    protected function buildRestBaseUrl()
    {
        return rtrim(site_url('/wp-json'), '/');
    }

    /**
     * Build the namespace and version segment of the REST URL.
     *
     * @return string
     */
    protected function buildNamespaceSegment()
    {
        // @phpstan-ignore-next-line
        $ns  = trim($this->app->config->get('app.rest_namespace'), '/');

        // @phpstan-ignore-next-line
        $ver = trim($this->app->config->get('app.rest_version'), '/');

        return "/{$ns}/{$ver}";
    }

    /**
     * Replace route placeholders in the URI with the provided parameters.
     *
     * @param  string  $template
     * @param  array   $params
     * @return string
     */
    protected function buildPath($template, &$params)
    {
        $replaced = preg_replace_callback(
            '/\{([^}]+)\??\}/',
            function ($m) use (&$params) {
                $key = rtrim($m[1], '?');
                return isset($params[$key]) ? $params[$key] : '';
            },
            $template
        );

        return '/' . trim($replaced, '/');
    }

    /**
     * Append query parameters to a URL.
     *
     * @param  string  $url
     * @param  array   $query
     * @return string
     */
    protected function appendQueryString($url, $query)
    {
        if (empty($query)) {
            return $url;
        }

        return $url . '?' . http_build_query($query);
    }
}
