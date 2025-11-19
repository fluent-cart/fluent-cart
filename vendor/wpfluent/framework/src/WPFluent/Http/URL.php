<?php

namespace FluentCart\Framework\Http;

use Exception;
use FluentCart\Framework\Support\Util;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Support\DateTime;
use FluentCart\Framework\Support\UriTemplate;

class URL
{
	protected $encrypter = null;

	public function __construct($encrypter = null)
	{
		$this->encrypter = $encrypter ?: App::make('encrypter');
	}

	/**
	 * Get the current URL
	 * 
	 * @return string
	 */
	public function current()
	{
		return get_site_url() . rtrim($_SERVER['REQUEST_URI'], '/');
	}

	/**
	 * Parse a url from uncompiled route
	 * 
	 * @param  string $url
	 * @param  array  $params
	 * @return string
	 */
	public function parse(string $url, array $params)
	{
		return UriTemplate::expand($url, $params);
	}

	/**
	 * Sign the current url.
	 * 
	 * @param  array $params
	 * @return string
	 */
	public function signCurrentUrl($params = [])
	{
		return $this->sign($this->current(), $params);
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
		if (!preg_match('#^(http|https)://#', $url)) {
			$config = App::config();
			$slug = $config->get('app.slug');
			$version = $config->get('app.rest_version');
	        $url = sprintf('%s%s/%s/%s',rest_url(), $slug, $version, $url);
	    }

		$parts = parse_url($url);

		$url = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];

		parse_str($parts['query'] ?? '', $query);
		
		$params = $this->validateExpiryTime($params + $query);

        $params = $this->encrypter->encrypt(http_build_query($params));
        
        $signature = hash_hmac('sha256', $params, $this->encrypter->getKey());
        
        return $url . '?_data=' . $params . '&_signature=' . $signature;
	}

	/**
	 * Normalize the expiry time.
	 * 
	 * @param  array $params
	 * @return array
	 * @throws Exception
	 */
	public function validateExpiryTime($params)
	{
	    if (isset($params['expires_at'])) {
	        if (is_string($params['expires_at'])) {
	            $params['expires_at'] = strtotime($params['expires_at']);
	        } elseif ($params['expires_at'] instanceof DateTime) {
	            $params['expires_at'] = $params['expires_at']->getTimestamp();
	        } elseif (is_numeric($params['expires_at'])) {
	            if ($params['expires_at'] <= time()) {
	                $params['expires_at'] = time() + (int) $params['expires_at'];
	            }
	        } else {
                throw new Exception('The expiry time is invalid or has passed.');
	        }
	    }

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
		$expected = hash_hmac('sha256', $data, $this->encrypter->getKey());

        if (!hash_equals($expected, $signature)) return false;

        parse_str($this->encrypter->decrypt($data), $params);

        $expiresAt = $params['expires_at'] ?? null;

		if (is_numeric($expiresAt) && time() > (int) $expiresAt) {
		    return false;
		}

        return empty($params) ? true : $params;
	}

	/**
	 * Helper method for home_url.
	 * 
	 * @return string
	 */
	public function wp($path = '')
	{
		$wp = get_option('siteUrl');

		if ($path) {
			$wp .= '/' . trim($path, '/');
		}

		return $wp;
	}

	/**
	 * Retrieve the wp-content URL.
	 *
	 * Note: The wp-content directory is renamable by devs so it's not
	 * guaranteed to be wp-content, so use this method for safety.
	 * 
	 * @param  string $path
	 * @return string
	 */
	public function content($path = '')
	{
		return content_url($path);
	}

	/**
	 * Retrieve the wp-content/plugins URL.
	 * 
	 * @param  string $path
	 * @return string
	 */
	public function plugins($path = '')
	{
    	return $this->content('/plugins/' . ltrim($path, '/'));
	}

	/**
	 * Retrieve the wp-content/plugins URL.
	 * 
	 * @param  string $path
	 * @return string
	 */
	public function plugin($path = '')
	{
    	$pluginUrl = rtrim(plugin_dir_url(
    		App::make('__pluginfile__')
    	), '/');

    	if ($path) {
    		$pluginUrl .= '/' . trim($path, '/');
    	}

    	return $pluginUrl;
	}

	/**
	 * Retrieve the wp-content/themes URL.
	 * 
	 * @param  string $path
	 * @return string
	 */
	public function themes($path = '')
	{
		return $this->content('/themes/' . ltrim($path, '/'));
	}

	/**
	 * Retrieve the wp-content/uploads URL.
	 * 
	 * @param  string $path
	 * @return string
	 */
	public function uploads($path = '')
	{
		return $this->content('/uploads/' . ltrim($path, '/'));
	}

	/**
	 * Retrieve the site/home URL.
	 * 
	 * @param  string $path
	 * @return string
	 */
	public function home($path = '', $scheme = null)
	{
		return site_url($path, $scheme);
	}

	/**
	 * Generate a URL from a file path.
	 * 
	 * @param  string $path
	 * @return string
	 */
	public function fromFile($path)
	{
		return Util::pathToUrl($path);
	}

	/**
	 * Generate a URL from a named route.
	 * 
	 * @param  string $name
	 * @param  array  $params
	 * @param  array  $query
	 * @return string|null
	 */
	public function route($name, $params = [], $query = [])
	{
	    return (new UrlGenerator)->route($name, $params, $query);
	}

	/**
	 * Returns the string representation of the URL object
	 * 
	 * @return string Current URL.
	 */
	public function __toString()
	{
		return $this->current();
	}
}
