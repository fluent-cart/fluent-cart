<?php

namespace FluentCart\Framework\Http\Middleware;

use FluentCart\Framework\Foundation\App;

class RateLimiter
{
    /**
     * The maximum number of requests allowed within the interval.
     *
     * @var int
     */
    protected $limit;

    /**
     * The time interval for the rate limit in seconds.
     *
     * @var int
     */
    protected $interval;

    /**
     * Constructor to initialize the rate limiter.
     *
     * @param int $limit    Maximum number of requests allowed.
     * @param int $interval Time interval for the rate limit in seconds.
     */
    public function __construct($limit, $interval)
    {
        $this->limit = $limit;
        $this->interval = $interval;
    }

    /**
     * Handle an incoming request and apply rate limiting.
     *
     * @param \FluentCart\Framework\Http\Request\Request $request
     * @param callable $next
     * 
     * @return mixed
     */
    public function handle($request, $next)
    {
        if ($this->shouldAllow($request)) {
            return $next($request);
        }

        $settings = $this->getSettings($request, $currentTime = time());

        if ($this->isIntervalExpired($settings, $currentTime)) {
            $settings = $this->resetRateLimit($currentTime);
        } else {
            $settings['count']++;
        }

        $this->updateSettings($request, $settings);

        if ($this->isRateLimitExceeded($settings)) {
            return $request->abort(429, 'Too many requests.');
        }

        return $next($request);
    }

    /**
     * Determine if the request should bypass rate limiting.
     *
     * @param \FluentCart\Framework\Http\Request\Request $request
     * 
     * @return bool
     */
    protected function shouldAllow($request)
    {
        return is_user_logged_in() || in_array(
            $request->method(), ['HEAD', 'OPTIONS']
        );
    }

    /**
     * Get the current rate limit settings for the request.
     *
     * @param \FluentCart\Framework\Http\Request\Request $request
     * @param int $currentTime
     * 
     * @return array
     */
    protected function getSettings($request, $currentTime)
    {
        $settings = $this->getTransient($request);
        return $settings ?: ['count' => 0, 'firstTime' => $currentTime];
    }

    /**
     * Check if the rate limit interval has expired.
     *
     * @param array $settings
     * @param int $currentTime
     * 
     * @return bool
     */
    protected function isIntervalExpired($settings, $currentTime)
    {
        return ($currentTime - $settings['firstTime']) > $this->interval;
    }

    /**
     * Reset the rate limit for a new interval.
     *
     * @param int $currentTime
     * 
     * @return array
     */
    protected function resetRateLimit($currentTime)
    {
        return ['count' => 1, 'firstTime' => $currentTime];
    }

    /**
     * Check if the rate limit has been exceeded.
     *
     * @param array $settings
     * 
     * @return bool
     */
    protected function isRateLimitExceeded($settings)
    {
        return $settings['count'] > $this->limit;
    }

    /**
     * Retrieve the transient data for the current request's rate limit.
     *
     * @param \FluentCart\Framework\Http\Request\Request $request
     * 
     * @return array|null
     */
    protected function getTransient($request)
    {
        return get_transient($this->makeTransientKey($request));
    }

    /**
     * Update the rate limit settings in the transient storage.
     *
     * @param \FluentCart\Framework\Http\Request\Request $request
     * @param array $settings
     * 
     * @return void
     */
    protected function updateSettings($request, $settings)
    {
        $key = $this->makeTransientKey($request);

        set_transient($key, $settings, $this->interval);
    }

    /**
     * Generate a unique transient key for the current request.
     *
     * @param \FluentCart\Framework\Http\Request\Request $request
     * 
     * @return string
     */
    protected function makeTransientKey($request)
    {
        $slug = App::config()->get('app.slug');

        return "{$slug}_rate_limit_" . md5($request->getIp());
    }
}
