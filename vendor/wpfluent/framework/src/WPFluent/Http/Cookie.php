<?php

namespace FluentCart\Framework\Http;

use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use FluentCart\Framework\Foundation\App;

class Cookie
{
    /**
     * Cookie queue
     * 
     * @var array
     */
    protected static $queue = [];

    /**
     * Action registered indicator.
     * 
     * @var bool
     */
    protected static $actionRegistered = false;

    /**
     * Set a cookie immediately.
     * 
     * @param string               $name
     * @param mixed                $value
     * @param int|DateTimeInterface $minutes
     * @param string               $path
     * @param string               $domain
     * @param bool                 $secure
     * @param bool                 $httponly
     * @param string               $samesite
     * 
     * @return void
     */
    public static function set(
        $name,
        $value,
        $minutes = 0,
        $path = '/',
        $domain = '',
        $secure = false,
        $httponly = false,
        $samesite = 'Lax'
    ) {
        $cookie = static::make(
            $name, 
            $value, 
            $minutes, 
            $path = '/', 
            $domain, 
            $secure, 
            $httponly, 
            $samesite
        );

        if (App::request()->isRest()) {
            $cookie['options']['expires'] = $minutes;
            static::setCookieInResponse($cookie);
        } else {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }
    }

    /**
     * Set a cookie that lasts 5 years.
     * 
     * @param string $name
     * @param mixed  $value
     * @param string $path = '/'
     * @param string $domain
     * @param bool   $secure
     * @param bool   $httponly
     * @param string $samesite
     * 
     * @return void
     */
    public static function setForever(
        $name,
        $value,
        $path = '/',
        $domain = '',
        $secure = false,
        $httponly = false,
        $samesite = 'Lax'
    ) {
        static::set(
            $name,
            $value,
            new DateTime('+5 years'),
            $path,
            $domain,
            $secure,
            $httponly,
            $samesite
        );
    }

    /**
     * Queue a cookie to be sent with headers.
     * 
     * @param string               $name
     * @param mixed                $value
     * @param int|DateTimeInterface $minutes
     * @param string               $path
     * @param string               $domain
     * @param bool                 $secure
     * @param bool                 $httponly
     * @param string               $samesite
     * 
     * @return void
     */
    public static function queue(
        $name,
        $value,
        $minutes = 0,
        $path = '/',
        $domain = '',
        $secure = false,
        $httponly = false,
        $samesite = 'Lax'
    ) {
        $cookie = static::make(
            $name,
            $value,
            $minutes,
            $path,
            $domain,
            $secure,
            $httponly,
            $samesite
        );

        if (App::request()->isRest()) {
            $cookie['options']['expires'] = $minutes;
            static::setCookieInResponse($cookie);
        } else {
            static::$queue[] = $cookie;
            static::maybeAddSendHeadersAction();
        }
    }

    /**
     * Handle WordPress's send_headers action.
     * 
     * @param mixed $wp
     * 
     * @return void
     */
    public static function sendHeaders($wp)
    {
        foreach (static::$queue as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }

        static::$queue = [];
        static::$actionRegistered = false;
    }

    /**
     * Get a cookie value.
     * 
     * @param string $name
     * @param mixed  $default
     * 
     * @return mixed
     */
    public static function get($name, $default = null)
    {
        return array_key_exists($name, $_COOKIE) ? $_COOKIE[$name] : $default;
    }

    /**
     * Delete a cookie.
     * 
     * @param string $name
     * @param string $path
     * @param string $domain
     * @param string $samesite
     * 
     * @return void
     */
    public static function delete(
        $name,
        $path = '/',
        $domain = '',
        $samesite = 'Lax'
    ) {
        $options = [
            'expires' => time() - 3600,
            'path' => $path,
            'domain' => $domain,
            'secure' => false,
            'httponly' => false,
            'samesite' => $samesite,
        ];

        setcookie($name, '', $options);
        unset($_COOKIE[$name]);
    }

    /**
     * Create a cookie array for setcookie().
     * 
     * @param string               $name
     * @param mixed                $value
     * @param int|DateTimeInterface $minutes
     * @param string               $path
     * @param string               $domain
     * @param bool                 $secure
     * @param bool                 $httponly
     * @param string               $samesite
     * 
     * @return array
     */
    public static function make(
        $name,
        $value,
        $minutes = 0,
        $path = '/',
        $domain = '',
        $secure = false,
        $httponly = false,
        $samesite = 'Lax'
    ) {
        return [
            'name' => $name,
            'value' => $value,
            'options' => [
                'expires'  => static::expiresAt($minutes),
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ],
        ];
    }

    /**
     * Convert minutes to expiration timestamp.
     * 
     * @param int|DateTimeInterface $minutes
     * 
     * @return int
     * 
     * @throws InvalidArgumentException
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
     * Register the send_headers action if not already registered.
     * 
     * @return void
     */
    protected static function maybeAddSendHeadersAction()
    {
        if (!static::$actionRegistered) {
            static::$actionRegistered = true;
            App::addAction('send_headers', [static::class, 'sendHeaders']);
        }
    }

    /**
     * Set cookie in Response when in rest context.
     * 
     * @param array $cookie
     */
    protected static function setCookieInResponse($cookie)
    {
        App::make('response')->withCookie(
            $cookie['name'],
            $cookie['value'],
            $cookie['options']['expires'],
            $cookie['options']['path'],
            $cookie['options']['domain'],
            $cookie['options']['secure'],
            $cookie['options']['httponly'],
            $cookie['options']['samesite']
        );
    }
}
