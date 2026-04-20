<?php

namespace FluentCart\Framework\Http;

use Exception;
use InvalidArgumentException;
use FluentCart\Framework\Support\Util;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Support\UriTemplate;

class URL
{
	/**
	 * The full URL string.
	 *
	 * @var string
	 */
	protected $url = '';

	/**
	 * URL generator instance used for signing and route generation.
	 *
	 * @var UrlGenerator|null
	 */
	protected $generator = null;

	/**
	 * URL scheme (e.g., 'http', 'https').
	 *
	 * @var string
	 */
	protected $scheme = '';

	/**
	 * URL host (e.g., 'example.com').
	 *
	 * @var string|null
	 */
	protected $host = null;

	/**
	 * URL port (e.g., 80, 443).
	 *
	 * @var int|null
	 */
	protected $port = null;

	/**
	 * URL path (e.g., '/users/view').
	 *
	 * @var string
	 */
	protected $path = '';

	/**
	 * URL query parameters as an associative array.
	 *
	 * @var array
	 */
	protected $query = [];

	/**
	 * URL fragment (e.g., 'top' in '#top').
	 *
	 * @var string|null
	 */
	protected $fragment = null;

	/**
	 * URL username for authentication (if any).
	 *
	 * @var string|null
	 */
	protected $user = null;

	/**
	 * URL password for authentication (if any).
	 *
	 * @var string|null
	 */
	protected $pass = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $url       Optional URL to initialize. Defaults to current URL.
	 * @param UrlGenerator|null $generator Optional generator instance for signing/routes.
	 *
	 * @throws InvalidArgumentException If the given URL is invalid.
	 */
	public function __construct($url = '', $generator = null)
	{
	    $this->prepareRfcProperties(
	        $this->url = $url ?: $this->current()
	    );

	    $this->generator = $generator ?: new UrlGenerator();
	}

	/**
	 * Parse a URL according to RFC 3986 and populate internal components.
	 *
	 * @param string $url The URL to parse.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the URL cannot be parsed.
	 */
	protected function prepareRfcProperties($url)
	{
	    // --- Parse URL according to RFC3986 ---
	    $parts = parse_url($url);

	    if ($parts === false) {
	        throw new InvalidArgumentException("Invalid URL: {$url}");
	    }

	    $this->scheme   = $parts['scheme']   ?? '';
	    $this->host     = $parts['host']     ?? null;
	    $this->port     = $parts['port']     ?? null;
	    $this->path     = $parts['path']     ?? null;
	    $this->user     = $parts['user']     ?? null;
	    $this->pass     = $parts['pass']     ?? null;
	    $this->fragment = $parts['fragment'] ?? null;

	    if (isset($parts['query'])) {
	        parse_str($parts['query'], $query);
	        $this->query = $query;
	    } else {
	        $this->query = [];
	    }
	}

    /**
     * Create a new URL instance.
     * 
     * @param  string $uri
     * @return static
     */
    public static function of($uri)
    {
        $parts = parse_url($uri);

        if ($parts === false) {
            throw new InvalidArgumentException("Invalid URI: {$uri}");
        }

        return new static($uri);
    }

	/**
	 * Get the current URL
	 * 
	 * @return string
	 */
	public function current()
	{
		return rtrim(get_site_url(), '/') . $_SERVER['REQUEST_URI'];
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
		return $this->generator->sign($url, $params);
	}

	/**
     * Validate a URL
     * 
     * @param  string $url
     * @return mixed (false or array)
     */
    public function validate($url)
    {
    	return $this->generator->validate($url);
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
	 * @param  bool   $checkFile Whether to check if the file exists
	 * @return string
	 */
	public function fromFile(string $path, bool $checkFile = false): string
	{
	    return Util::pathToUrl($path, $checkFile);
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
	    return $this->generator->route($name, $params, $query);
	}

	/**
	 * Return a clone of the URL with a new path.
	 *
	 * @param string $path
	 * @return $this
	 */
	public function withPath($path)
	{
	    $clone = clone $this;
	    $clone->path = $path;
	    return $clone;
	}

	/**
	 * Return a clone of the URL with a new query.
	 *
	 * @param array|string $query  An array of query params or a query string
	 * @return $this
	 */
	public function withQuery($query)
	{
	    $clone = clone $this;

	    if (is_string($query)) {
	        parse_str($query, $query);
	    }

	    $clone->query = $query;
	    return $clone;
	}

	/**
	 * Return a clone of the URL with a new fragment.
	 *
	 * @param string $fragment
	 * @return $this
	 */
	public function withFragment($fragment)
	{
	    $clone = clone $this;
	    $clone->fragment = $fragment;
	    return $clone;
	}

	/**
	 * Return a clone of the URL with a new scheme.
	 *
	 * @param string $scheme
	 * @return $this
	 */
	public function withScheme($scheme)
	{
	    $clone = clone $this;
	    $clone->scheme = $scheme;
	    return $clone;
	}

	/**
	 * Get the URL scheme (e.g., 'http' or 'https').
	 *
	 * @return string
	 */
	public function scheme()
	{
	    return $this->scheme;
	}

	/**
	 * Get the URL host.
	 *
	 * @return string|null
	 */
	public function host()
	{
	    return $this->host;
	}

	/**
	 * Get the URL port.
	 *
	 * @return int|null
	 */
	public function port()
	{
	    return $this->port;
	}

	/**
	 * Get the URL path.
	 *
	 * @return string
	 */
	public function path()
	{
	    return $this->path;
	}

	/**
	 * Get the URL query as an array.
	 *
	 * @return array
	 */
	public function query()
	{
	    return $this->query;
	}

	/**
	 * Get the URL fragment.
	 *
	 * @return string|null
	 */
	public function fragment()
	{
	    return $this->fragment;
	}

	/**
	 * Get the URL pass.
	 * 
	 * @return string|null
	 */
	public function pass()
	{
		return $this->pass;
	}

	/**
	 * Get the URL user.
	 * 
	 * @return string
	 */
	public function user()
	{
		return $this->user;
	}

	/**
	 * Returns the string representation of the URL object.
	 * 
	 * @return string Current URL.
	 */
	public function __toString()
	{
	    $url = '';

		if ($this->scheme) {
		    $url .= "{$this->scheme}://";
		}

		if ($this->user) {
		    $url .= $this->user;
		    if ($this->pass) {
		        $url .= ":{$this->pass}";
		    }
		    $url .= '@';
		}

		if ($this->host) {
		    $url .= $this->host;
		}

		if ($this->port) {
		    $url .= ":{$this->port}";
		}

		if ($this->path !== null) {
		    $url .= $this->path === '' || str_starts_with($this->path, '/')
		        ? $this->path
		        : '/' . $this->path;
		}

		if (!empty($this->query)) {
		    $url .= '?' . http_build_query(
		    	$this->query, '', '&', PHP_QUERY_RFC3986
		    );
		}

		if ($this->fragment) {
		    $url .= "#{$this->fragment}";
		}

		return $url;
	}
}
