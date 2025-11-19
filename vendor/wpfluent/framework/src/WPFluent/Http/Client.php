<?php

namespace FluentCart\Framework\Http;

use Exception;
use BadMethodCallException;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Http\Request\File;

/**
 * @method mixed get(string $url, array $params = [])     Send a GET request.
 * @method mixed post(string $url, array $params = [])    Send a POST request.
 * @method mixed put(string $url, array $params = [])     Send a PUT request.
 * @method mixed patch(string $url, array $params = [])   Send a PATCH request.
 * @method mixed delete(string $url, array $params = [])  Send a DELETE request.
 * @method mixed head(string $url, array $params = [])    Send a HEAD request.
 * @method mixed options(string $url, array $params = []) Send an OPTIONS request.
 * @method File download(string|File $url)                Download a remote file.
 * @method mixed upload(string $url, string $path, array $fields = [], string $name = 'file') Upload a file to a remote server.
 */


/**
 * Some examples:
 * 
 * // Using an instance:
 * $client = Client::make('https://example.com');
 * 
 * $response1 = $client->get('/users');
 * $response2 = $client->post('/users', ['body' => ['name' => 'Heera']]);
 * $response3 = $client->put('/users/1', ['body' => ['name' => 'Updated']]);
 * $response4 = $client->patch('/users/1', [
 *     'body' => ['email' => 'updated@example.com']]
 * );
 * $response5 = $client->delete('/users/1');
 * $response6 = $client->head('/users');
 * $response7 = $client->options('/users');
 * 
 * // Using statically (default base URL is empty):
 * $response8 = Client::get('https://example.com/users');
 * $response9 = Client::post('https://example.com/users', [
 *     'body' => ['name' => 'Static']]
 * );
 */


class Client
{
	/**
	 * Base URl for the request.
	 * 
	 * @var string
	 */
	protected $baseUrl = '';
	
	/**
	 * Cookies to send with the request.
	 * 
	 * @var array
	 */
	protected $cookies = [];
	
	/**
	 * Headers to send with the request.
	 * 
	 * @var array
	 */
	protected $headers = [];
	
	/**
	 * Options to set for the request.
	 * 
	 * @var array
	 */
	protected $options = [];

	/**
	 * Request body|Data|params to set in the request.
	 * 
	 * @var array
	 */
	protected $body = [];

	/**
	 * Request query params to pass with the url.
	 * 
	 * @var array
	 */
	protected $query = [];

	/**
	 * Stores args temporarily for then().
	 * 
	 * @var null|array
	 */
	private $args = null;

	/**
	 * Create a new HTTP client.
	 *
	 * @param string $baseUrl
	 * @param array $args
	 */
	public function __construct($baseUrl = '', $args = [])
	{
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->cookies = $args['cookies'] ?? [];
		$this->headers = $args['headers'] ?? [];
		$this->options = $args['options'] ?? [];
	}

	/**
	 * Create a new HTTP client.
	 * 
	 * @param string $baseUrl
	 * @param array $args
	 */
	public static function make($baseUrl = '', $args = [])
	{
		$args['cookies'] = $args['cookies'] ?? [];
		$args['headers'] = $args['headers'] ?? [];
		$args['options'] = $args['options'] ?? [];
		return new static($baseUrl, $args);
	}

	/**
	 * Sets one or more options.
	 * 
	 * @return self
	 */
	public function withOption($key, $value = null)
	{
		$options = is_array($key) ? $key : [$key => $value];

		foreach ($options as $key => $value) {
			$this->options[$key] = $value;
		}

		return $this;
	}

	/**
	 * Sets the blocking option to false (non-blocking).
	 * 
	 * @return self
	 */
	public function async()
	{
		return $this->withOption('blocking', false);
	}

	/**
	 * Sets the sslverify option.
	 * 
	 * @return self
	 */
	public function secure($verify = true)
	{
		return $this->withOption('sslverify', $verify);
	}

	/**
	 * Sets one or more headers.
	 * 
	 * @return self
	 */
	public function withHeader($key, $value = null)
	{
		$headers = is_array($key) ? $key : [$key => $value];

		foreach ($headers as $key => $value) {
			$this->headers[$key] = $value;
		}

		return $this;
	}

	/**
	 * Sets one or more headers.
	 * 
	 * @param  array $headers
	 * @return self
	 */
	public function withHeaders(array $headers)
	{
		return $this->withHeader($headers);
	}

	/**
	 * Sets the Authorization header.
	 * 
	 * @param  string $token
	 * @param  string $type
	 * @return self
	 */
	public function withToken($token, $type = 'Bearer')
	{
	    return $this->withHeader([
	        'Authorization' => $type . ' ' . $token,
	    ]);
	}

	/**
	 * Sets one or more cookies.
	 * 
	 * @return self
	 */
	public function withCookie($key, $value = null)
	{
		$cookies = is_array($key) ? $key : [$key => $value];

		foreach ($cookies as $key => $value) {
			$this->cookies[$key] = $value;
		}

		return $this;
	}

	/**
	 * Sets one or more request body param.
	 * 
	 * @return self
	 */
	public function withData($key, $value = null)
	{
		$data = is_array($key) ? $key : [$key => $value];

		foreach ($data as $key => $value) {
			$this->body[$key] = $value;
		}

		return $this;
	}

	/**
	 * Sets one or more request body param.
	 * 
	 * @return self
	 */
	public function withBody($key, $value = null)
	{
		return $this->withData($key, $value);
	}

	/**
	 * Sets one or more request body param.
	 * 
	 * @return self
	 */
	public function withParam($key, $value = null)
	{
		return $this->withData($key, $value);
	}

	/**
	 * Sets one or more request body param.
	 * 
	 * @return self
	 */
	public function withQuery($key, $value = null)
	{
		$data = is_array($key) ? $key : [$key => $value];
		
		foreach ($data as $key => $value) {
			$this->query[$key] = $value;
		}

		return $this;
	}

	/**
     * Allows users to enable streaming on their requests.
     *
     * @return self
     */
    public function withStreaming($callback = null)
    {
        return $this->withOption(
        	'stream', true
        )->withOption('stream_callback', $callback);
    }

	/**
	 * Build the request arguments.
	 * 
	 * @param  array  $params
	 * @param  string $method 
	 * @return array
	 */
	protected function buildRequestArgs($params, $method)
	{
		$defaultParams = [
	        'body' => [],
	        'cookies' => [],
	        'headers' => [],
	    ];

	    $params = wp_parse_args($params[0] ?? [], $defaultParams);

	    $options = array_merge($this->options, $params['options'] ?? []);

	    if (Str::isJson($params['body'])) {
	    	$this->withHeader('Content-Type', 'application/json');
	    	$params['body'] = json_decode($params['body'], true);
	    }

	    $params = [
	        'method' => strtoupper($method),
	        'body' => array_merge($this->body, $params['body']),
	        'cookies' => array_merge($this->cookies, $params['cookies']),
	        'headers' => array_merge($this->headers, $params['headers']),
	    ];

	    foreach($options as $key => $value) {
	        $params[$key] = $value;
	    }

	    return $params;
	}

	/**
	 * Send the request.
	 * 
	 * @param  string $url
	 * @param  array  $args
	 * @return \FluentCart\Framework\Http\Response
	 */
	protected function request($url, $args = [])
	{
		return $this->dispatch(
			$this->resolveUrl($url),
			$this->filterArgs($args)
		);
	}

	/**
	 * Build the URL.
	 * 
	 * @param  string $url
	 * @return string
	 */
	protected function resolveUrl($url)
	{
		$q = $this->query;

		$parsedUrl = parse_url($url);
		
		$delimiter = isset($parsedUrl['query']) ? '&' : '?';
		
		return $url . ($q ? $delimiter . http_build_query($q) : '');

	}

	/**
	 * Filter the args before sending the request.
	 * 
	 * @param  array $args
	 * @return array
	 */
	protected function filterArgs($args)
	{
		if ($this->shouldBeJson($args)) {
	        $args['body'] = json_encode($args['body']);
	    }

	    return $args;
	}

	/**
	 * Encode the body if Content-Type is JSON.
	 * 
	 * @param  array $params
	 * @return bool
	 */
	protected function shouldBeJson($params)
	{
		return isset(
			$params['headers']['Content-Type']
		) && $params['headers']['Content-Type'] === 'application/json';
	}

	/**
	 * Dispatch the request.
	 * 
	 * @param  string $url
	 * @param  array $args
	 * @return array (response)
	 */
	protected function dispatch($url, $args)
	{
		$response = wp_remote_request($url, $args);

	    if (is_wp_error($response)) {
	        throw new Exception($response->get_error_message(), 500);
	    }

	    $this->mergeCookies($response);

	    if ($this->isStreamEnabled($args)) {
            return $this->makeStreamResponse($response);
        }

	    return $this->makeResponse($response);
	}

	/**
	 * Merge the coolkies (useful for stateful request).
	 * 
	 * @return void
	 */
	protected function mergeCookies($response)
	{
		$this->cookies = array_merge(
	        $this->cookies,
	        wp_remote_retrieve_cookies($response)
	    );
	}

	/**
	 * Check if the stream is enabled.
	 * @return boolean
	 */
	protected function isStreamEnabled($args)
	{
		return isset($args['stream']) && $args['stream'];
	}

	/**
	 * Build a response object from an anonymous class.
	 * 
	 * @param  array $response
	 * @return \FluentCart\Framework\Http\Response
	 */
	protected function makeResponse($response)
	{
		return new Response($response);
	}

	/**
     * Handle the streaming response and process chunks.
     *
     * @param  array $response
     * @return \FluentCart\Framework\Http\Response|null
     */
    protected function makeStreamResponse($response)
	{
	    $response['body'] = $response['filename'];

	    return new class($response) extends Response implements \ArrayAccess {
	    	public function flush() {
				$source = fopen($this->response['body'], 'rb');
			    
			    $fp = fopen('php://output', 'wb');

			    while (!feof($source)) {
			        $data = fread($source, 8192);
			        fwrite($fp, $data);
			        ob_flush();
			        flush();
			    }

			    fclose($source);
			    fclose($fp);
			}

			#[ReturnTypeWillChange]
		    public function offsetGet($offset) {
		        return $this->response[$offset] ?? null;
		    }
		    #[ReturnTypeWillChange]
		    public function offsetSet($offset, $value) {
		    	$this->response[$offset] = $value;
		    }
		    #[ReturnTypeWillChange]
			public function offsetExists($offset) {}
		    #[ReturnTypeWillChange]
		    public function offsetUnset($offset) {}
		};
	}

	/**
	 * Download a remote file.
	 * 
	 * @param  string $url
	 * @return \FluentCart\Framework\Http\Request\File
	 * @throws \Exception
	 */
	public function downloadFile($url)
	{
		if (!function_exists('download_url')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$parsed = parse_url($url);

		if (!isset($parsed['scheme'])) {
			$url = trim($this->baseUrl, '/') . '/' . trim($url, '/');
		}

		if (is_wp_error($file = download_url($url))) {
			throw new Exception($file->get_error_message(), 500);
		}

		add_action('shutdown', function () use ($file) {
			@unlink($file);
		});

		return new File(
			$file,
			basename($url),
			mime_content_type($file) ?: 'application/octet-stream',
			filesize($file),
			UPLOAD_ERR_OK
		);
	}

	/**
	 * Upload  a file to a remote server.
	 * 
	 * @param  string $url
	 * @param  string $path
	 * @param  array  $fields
	 * @param  string $name
	 * @return \FluentCart\Framework\Http\Response
	 */
	public function uploadFile($url, $path, $fields = [], $name = 'file')
	{
	    $path = $path instanceof File ? $path->getPathname() : $path;

	    if (!file_exists($path)) {
	        throw new Exception('File does not exist.', 500);
	    }

	    // Auto prepend base URL if $url is relative
	    if (strpos($url, 'http') !== 0) {
	        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
	    }

	    $boundary = wp_generate_password(24, false);

	    $headers = array_merge(
	        $this->headers,
	        [
	            'Accept'       => '*/*',
	            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
	        ]
	    );

	    $fileName = basename($path);
	    $content = file_get_contents($path);
	    $mime = mime_content_type($path);

	    $body = '';

	    foreach ($fields as $key => $value) {
	        $body .= "--" . $boundary . "\r\n";
	        $body .= 'Content-Disposition: form-data; name="' . $key . '"' . "\r\n\r\n";
	        $body .= $value . "\r\n";
	    }

	    $body .= "--" . $boundary . "\r\n";
	    $body .= 'Content-Disposition: form-data; name="'.$name.'"; filename="' . $fileName . '"' . "\r\n";
	    $body .= 'Content-Type: ' . $mime . "\r\n\r\n";
	    $body .= $content . "\r\n";
	    $body .= "--" . $boundary . "--\r\n";

	    $response = wp_remote_post($url, [
	        'headers' => $headers,
	        'body'    => $body,
	        'timeout' => 60,
	    ]);

	    return $this->makeResponse($response);
	}

	protected function checkIfValidHttpMethod($method)
	{
		$validHttpMethods = [
			'get', 'post', 'put', 'delete', 'patch', 'options', 'head'
		];

		if (!in_array(strtolower($method), $validHttpMethods)) {
			throw new BadMethodCallException("Method $method does not exist.");
		}
	}

	/**
	 * Handles the dynamic calls.
	 * 
	 * @param  string $method
	 * @param  array  $args
	 * @return mixed
	 */
	public function __call($method, $args)
	{
		if ($method === 'download') {
			return $this->downloadFile(...$args);
		}
		
		// Handles dynamic method calls like:
		// get, post and so on.
		$url = array_shift($args);

		$parsed = parse_url($url);

		if (!isset($parsed['scheme'])) {
			$url = trim($this->baseUrl, '/') . '/' . trim($url, '/');
		}
		
		$this->checkIfValidHttpMethod($method);

		return $this->request(
			$url, $this->buildRequestArgs($args, $method)
		);
	}

	/**
	 * Handle the static dynamic calls.
	 * 
	 * @param  string $method
	 * @param  array  $args
	 * @return self
	 */
	public static function __callStatic($method, $args)
	{
		return static::make()->$method(...$args);
	}
}
