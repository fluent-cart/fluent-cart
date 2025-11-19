<?php

namespace FluentCart\Framework\Http\Response;

use DateTime;
use WP_Error;
use DateTimeInterface;
use WP_REST_Response;
use FluentCart\Framework\App\App;
use InvalidArgumentException;
use FluentCart\Framework\Http\Request\File;

class Response
{
    /**
     * Response Data
     * @var mixed
     */
    protected $data = null;
    
    /**
     * Response Status Code
     * @var integer
     */
    protected $code = 200;
    
    /**
     * Response Headers
     * @var array
     */
    protected $headers = [];
    
    /**
     * Response Cookies
     * @var array
     */
    protected $cookies = [];

    /**
     * Construct the response instance.
     * 
     * @param  array  $data
     * @param  integer $code
     * @param  array $headers
     */
    public function __construct($data = null, $code = 200, $headers = [])
    {
        $this->data = $data;
        $this->code = $code;
        $this->headers = $headers;
    }

    /**
     * Creates an instance of self.
     * 
     * @param  mixed  $data
     * @param  integer $code
     * @param  array $headers
     * @return self
     */
    public static function make($data = null, $code = 200, $headers = [])
    {
        return new static($data, $code, $headers);
    }

    /**
     * Send json response.
     * 
     * @param  array  $data
     * @param  integer $code
     * @return string|false JSON encoded string, or false if fails to encode.
     */
    public function json($data = null, $code = 200)
    {    
        return wp_send_json($data, $code);
    }

    /**
     * Send rest response.
     * 
     * @param  mixed  $data
     * @param  integer $code
     * @param  array $headers
     * @return \WP_REST_Response
     */
    public function send($data = null, $code = 200, $headers = [])
    {
        $response = new WP_REST_Response($data, $code, $headers);

        return $this->maybeMergeHeaders(
            $this->maybeMergeCookies($response)
        );
    }

    /**
     * Send a success rest response.
     * 
     * @param  mixed  $data
     * @param  integer $code
     * @return \WP_REST_Response
     */
    public function sendSuccess($data = null, $code = 200, $headers = [])
    {   
         return $this->send($data, $code, $headers);
    }

    /**
     * Send an error json response
     * @param  array  $data
     * @param  integer $code
     * @return \WP_REST_Response
     */
    public function sendError($data = null, $code = 422, $headers = [])
    {
        if (!$code || $code < 400 ) {
            $code = 422;
        }

        return $this->send($data, $code, $headers);
    }

    /**
     * Convert the WP_Error to WP_REST_Response
     * 
     * @param  \WP_Error $wpError
     * @return \WP_REST_Response
     */
    public function wpErrorToResponse(WP_Error $wpError)
    {
        return rest_convert_error_to_response($wpError);
    }

    /**
     * Set response headers
     * @param  string|array $key
     * @param  string|null $value
     * @return self
     */
    public function withHeader($key, $value = null)
    {
        if (is_array($key) && !$value) {
            $this->headers = $key;
        } else {
            $this->headers = [$key => $value];
        }

        return $this;
    }

    /**
     * Set response cookie
     * 
     * @param  string       $name
     * @param  mixed        $value
     * @param  int          $minutes
     * @param  string       $path
     * @param  string|null  $domain
     * @param  bool         $secure
     * @param  bool         $httpOnly
     * @param string        $samesite
     * @return              self
     */
    public function withCookie(
        $name,
        $value,
        $minutes,
        $path = '/',
        $domain = null,
        $secure = false,
        $httpOnly = true,
        $samesite = 'Lax'
    )
    {
        $cookie = $this->buildCookie(
            $name,
            $value,
            $minutes,
            $path,
            $domain,
            $secure,
            $httpOnly,
            $samesite
        );

        $this->cookies[] = $cookie;

        return $this;
    }

    /**
     * Set response cookie
     * 
     * @param  string       $name
     * @param  mixed        $value
     * @param  int          $minutes
     * @param  string       $path
     * @param  string|null  $domain
     * @param  bool         $secure
     * @param  bool         $httpOnly
     * @param string        $samesite
     * @return              string
     */
    protected function buildCookie(
        $name,
        $value,
        $minutes,
        $path,
        $domain,
        $secure,
        $httpOnly,
        $samesite = 'Lax'
    ) {
        $expiration = static::expiresAt($minutes);

        $cookieHeader = "{$name}=" . rawurlencode($value);

        if ($expiration !== 0) {
            $cookieHeader .= "; Expires=" . gmdate(
                'D, d-M-Y H:i:s T', $expiration
            );
        }

        $path = $path ?: '/';
        $cookieHeader .= "; Path=" . $path;

        if ($domain) {
            $cookieHeader .= "; Domain=" . $domain;
        }

        if ($secure) {
            $cookieHeader .= "; Secure";
        }

        if ($httpOnly) {
            $cookieHeader .= "; HttpOnly";
        }

        if ($samesite) {
            $cookieHeader .= "; SameSite=" . ucfirst(strtolower($samesite));
        }

        return $cookieHeader;
    }

    /**
     * Merge additional headers if exist.
     * 
     * @param \WP_REST_Response $response
     */
    protected function maybeMergeHeaders(WP_REST_Response $response)
    {
        if ($this->headers) {
            foreach ($this->headers as $key => $value) {
                $response->header($key, $value);
            }
        }

        $this->headers = [];

        return $response;
    }

    /**
     * Merge cookies if exist.
     * 
     * @param \WP_REST_Response $response
     */
    protected function maybeMergeCookies(WP_REST_Response $response)
    {
        if ($this->cookies) {
            foreach ($this->cookies as $cookie) {
                $response->header('Set-Cookie', $cookie);
            }
        }

        $this->cookies = [];

        return $response;
    }

    /**
     * Prepares expiration time in minutes.
     * 
     * @param  int|DateTimeInterface $minutes
     * @return int
     */
    protected static function expiresAt($minutes = 0)
    {
        if ($minutes === 0) {
            return 0;
        }

        if (is_int($minutes)) {
            $minutes = new DateTime("+{$minutes} minutes");
        }

        if ($minutes instanceof DateTimeInterface) {
            return $minutes->getTimestamp();
        }

        throw new InvalidArgumentException('Invalid expiration time provided.');
    }

    /**
     * Send the response
     * 
     * @return \WP_REST_Response
     */
    public function toArray()
    {
        if ($this->data instanceof WP_Rest_Response) {
            $response = $this->data;
        } else {
            $response = new WP_REST_Response(
                $this->data, $this->code
            );
        }

        return $this->maybeMergeCookies(
            $this->maybeMergeHeaders($response)
        );
    }

    /**
     * Get data from response.
     * 
     * @return mixed
     */
    public function getData()
    {
        if ($this->data instanceof WP_Rest_Response) {
            return $this->data->get_data();
        }

        return $this->data;
    }

    /**
     * Set data to response.
     * 
     * @param  mixed $data
     * @return void
     */
    public function SetData($data)
    {
        if ($data instanceof WP_Rest_Response) {
            $this->data->set_data($data);
            return;
        }

        $this->data = $data;
    }

     /**
     * Send a file download response.
     *
     * @param string $filePath
     * @param string|null $fileName
     * @return void
     */
    public static function download($filePath, $fileName = null)
    {
        if ($filePath instanceof File) {
            $array = $filePath->toArray();
            $filePath = $array['tmp_name'];
            $fileName = $fileName ?? ($array['name'] ?? null);
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            wp_die('File does not exist or unreadable.', '404 Not Found');
        }

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        $fileName = sanitize_file_name($fileName ?? basename($filePath));

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        if (php_sapi_name() === 'cli') {
            ob_start();
            readfile($filePath);
            $content = ob_get_clean();
            add_action('shutdown', function() { exit; });
            return $content;
        } else {
            ob_get_level() && ob_end_clean() && flush();
            readfile($filePath);
            exit;
        }
    }

    /**
     * Send a redirect response.
     * 
     * @param  string  $route
     * @param  integer $status
     * @return \WP_REST_Response
     */
    public static function redirect($route, $status = 303)
    {
        // @phpstan-ignore-next-line
        [$ns, $ver] = App::config()->only('rest_namespace', 'rest_version');
        
        $baseUrl = rest_url("{$ns}/{$ver}");

        $parsedUrl = parse_url($route);

        $path = trim($parsedUrl['path'] ?? '', '/');
        
        parse_str($parsedUrl['query'] ?? '', $queryParams);

        $queryParams['x_redirect_to'] = $path;
        
        // @phpstan-ignore-next-line
        $queryParams['x_redirected_from'] = App::request()->url();

        $location = "{$baseUrl}/{$path}?" . http_build_query($queryParams);

        return new WP_REST_Response(null, $status, [
            'Location' => $location
        ]);
    }
}
