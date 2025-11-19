<?php

namespace FluentCart\Framework\Http;

/**
 * Class Response
 *
 * Handles HTTP responses for HTTP Client class,
 * providing methods to access response data.
 */
class Response implements \JsonSerializable
{
    /**
     * The HTTP response data.
     *
     * @var array
     */
    protected $response;

    /**
     * Create a new Response instance.
     *
     * @param array $response The response data.
     */
    public function __construct($response)
    {
        $this->response = $response;
    }

    /**
     * Convert the response to an array.
     *
     * @return array
     */
    public function toArray()
    {
        return $this->response;
    }

    /**
     * Convert the response object to an array.
     *
     * @return array
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Get the HTTP response object.
     *
     * @return mixed
     */
    public function getHttpResponseObject()
    {
        return $this->response['http_response'];
    }

    /**
     * Determine if the response is successful (2xx status code).
     *
     * @return bool
     */
    public function isOkay()
    {
        return str_starts_with($this->getCode(), '2');
    }

    /**
     * Determine if the response is a redirect (3xx status code).
     *
     * @return bool
     */
    public function isRedirect()
    {
        $code = $this->getCode();

        $isRedirect = in_array(
        	$code, [300, 301, 302, 303, 307], true
        ) || ($code > 307 && $code < 400);

        return $isRedirect || $this->getHttpResponseObject()
        	->get_response_object()
        	->redirects > 0;
    }

    /**
     * Throw an exception based on the response status code.
     *
     * @throws \WpOrg\Requests\Exception
     */
    public function throw()
    {
        $class = sprintf(
        	'WpOrg\Requests\Exception\Http\Status%d', $this->getCode()
        );
        
        if (!class_exists($class)) {
            $class = 'WpOrg\Requests\Exception\Http\Status\Http';
        }
        
        throw new $class;
    }

    /**
     * Throw an exception if the given callback returns true.
     *
     * @param callable $callback
     * @return void
     */
    public function throwIf(callable $callback)
    {
        if ($callback($this)) {
            return $this->throw();
        }
    }

    /**
     * Get the HTTP response status code.
     *
     * @return int
     */
    public function getCode()
    {
        return wp_remote_retrieve_response_code($this->response);
    }

    /**
     * Get the HTTP response status code (alias for getCode).
     *
     * @return int
     */
    public function getStatus()
    {
        return $this->getCode();
    }

    /**
     * Get the HTTP response status message.
     *
     * @return string
     */
    public function getMessage()
    {
        return wp_remote_retrieve_response_message($this->response);
    }

    /**
     * Get the HTTP response body.
     *
     * @return string
     */
    public function getBody()
    {
        return wp_remote_retrieve_body($this->response);
    }

    /**
     * Determine if the response body is JSON.
     *
     * @return bool
     */
    public function isJson()
    {
        $header = $this->getHeader('content-type');
        return str_contains($header, 'application/json');
    }

    /**
     * Get the decoded JSON body.
     *
     * @return array|null
     */
    public function getJson()
    {
        if ($this->isJson()) {
            return json_decode($this->getBody(), true);
        }

        return null;
    }

    /**
     * Get the headers from the response.
     *
     * @return array
     */
    public function getHeaders()
    {
        return wp_remote_retrieve_headers($this->response);
    }

    /**
     * Get a specific header from the response.
     *
     * @param string $key The header key.
     * @return string|null
     */
    public function getHeader($key)
    {
        return wp_remote_retrieve_header($this->response, $key);
    }

    /**
     * Get the cookies from the response.
     *
     * @return array
     */
    public function getCookies()
    {
        return wp_remote_retrieve_cookies($this->response);
    }

    /**
     * Get a specific cookie from the response.
     *
     * @param string $name The cookie name.
     * @param bool $isObject Whether to return the \WP_Http_Cookie object.
     * @return mixed
     */
    public function getCookie($name, $isObject = false)
    {
        if ($isObject) {
            // Return the \WP_Http_Cookie object
            return wp_remote_retrieve_cookie($this->response, $name);
        }

        return wp_remote_retrieve_cookie_value($this->response, $name);
    }
}
