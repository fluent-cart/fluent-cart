<?php

namespace FluentCart\Framework\Http;

use InvalidArgumentException;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Support\DateTime;

class UrlGenerator
{
    /**
     * The application instance.
     *
     * @var \FluentCart\Framework\Foundation\App|null
     */
    protected $app = null;
    

    /**
     * The encrypter instance.
     * @var \FluentCart\Framework\Encryption\Encrypter|null
     */
    protected $encrypter = null;

    /**
     * Create a new URL Generator instance.
     *
     * @param  \FluentCart\Framework\Foundation\App|null  $app
     * @param  \FluentCart\Framework\Encryption\Encrypter|null  $encrypter
     */
    public function __construct($app = null, $encrypter = null)
    {
        $this->app = $app ?: App::getInstance();
        $this->encrypter = $encrypter;
    }

    /**
     * Sign a URL
     * 
     * @param  string $url
     * @param  array $params
     * @return string
     */
    public function sign($url, $params = [])
    {
        $encrypter = $this->resolveEncrypter();

        $url = $this->normalizeUrl($url);

        [$baseUrl, $query] = $this->extractUrlParts($url);

        $params = $this->validateExpiryTime(array_merge($query, $params));

        $payload = $encrypter->encrypt(http_build_query($params));
        
        $signature = hash_hmac('sha256', $payload, $encrypter->getKey());

        return "{$baseUrl}?_data={$payload}&_signature={$signature}";
    }

    /**
     * Normalize URL — handle relative routes, slugs, and REST routes.
     * 
     * @param  string $url
     * @return string
     */
    protected function normalizeUrl($url)
    {
        if (preg_match('#^(http|https)://#', $url)) {
            return $url;
        }

        $config = App::config();
        $slug = trim($config->get('app.slug'), '/');
        $version = trim($config->get('app.rest_version'), '/');
        $relative = ltrim($url, '/');

        $base = rest_url();
        
        if (str_contains($base, 'index.php?rest_route=')) {
            $base = site_url('/wp-json/');
        }

        return rtrim($base, '/') . '/' . $slug . '/' . $version . '/' . $relative;
    }

    /**
     * Extract base URL and query array from a full URL.
     *
     * @param  string $url
     * @return array
     */
    protected function extractUrlParts($url)
    {
        $parts = parse_url($url);

        $base = $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '');
        parse_str($parts['query'] ?? '', $query);

        return [$base, $query];
    }

    /**
     * Normalize the expiry time.
     * 
     * @param  array $params
     * @return array
     * @throws InvalidArgumentException
     */
    public function validateExpiryTime(array $params): array
    {
        if (!isset($params['expires_at'])) {
            return $params; // Nothing to validate
        }

        $expiresAt = $params['expires_at'];

        // Convert string date/time to timestamp
        if (is_string($expiresAt)) {
            $expiresAt = strtotime($expiresAt);
            if ($expiresAt === false) {
                throw new InvalidArgumentException(
                    'The expiry time string is invalid.'
                );
            }

        // Convert DateTime object to timestamp
        } elseif ($expiresAt instanceof DateTime) {
            $expiresAt = $expiresAt->getTimestamp();

        // Numeric values are treated as absolute timestamps
        } elseif (is_numeric($expiresAt)) {
            $expiresAt = (int) $expiresAt;

        // Anything else is invalid
        } else {
            throw new InvalidArgumentException(
                'The expiry time must be a string, DateTime, or numeric timestamp.'
            );
        }

        // Check if the expiry time is in the past
        if ($expiresAt <= time()) {
            throw new InvalidArgumentException('The expiry time has already passed.');
        }

        $params['expires_at'] = $expiresAt;

        return $params;
    }

    /**
     * Validate a URL
     * 
     * @param  string $url
     * @return mixed (false or array)
     */
    public function validate($url)
    {
        if (!$query = $this->parseUrlAndGetQuery($url)) {
            return false;
        }

        if (!isset($query['_data']) || !isset($query['_signature'])) {
            return false;
        }

        return $this->verifySignature($query['_data'], $query['_signature']);
    }

    /**
     * Parse query string from the url.
     * 
     * @param  string $url
     * @return mixed (bool or array)
     */
    public function parseUrlAndGetQuery($url)
    {
        $parts = parse_url($url);

        if (!isset($parts['query'])) {
            return false;
        }

        parse_str($parts['query'], $query);

        return $query;
    }

    /**
     * Verify the signature.
     * 
     * @param  array $data
     * @param  string $signature
     * @return mixed (bool or array)
     */
    public function verifySignature($data, $signature)
    {
        $encrypter = $this->resolveEncrypter();

        $expected = hash_hmac('sha256', $data, $encrypter->getKey());

        if (!hash_equals($expected, $signature)) return false;

        parse_str($encrypter->decrypt($data), $params);

        $expiresAt = $params['expires_at'] ?? null;

        if (is_numeric($expiresAt) && time() > (int) $expiresAt) {
            return false;
        }

        return empty($params) ? true : $params;
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
     * Resolve the encrypter.
     * 
     * @return \FluentCart\Framework\Encryption\Encrypter
     */
    protected function resolveEncrypter()
    {
        if (!$this->encrypter) {
            $this->encrypter = App::make('encrypter');
        }

        return $this->encrypter;
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
