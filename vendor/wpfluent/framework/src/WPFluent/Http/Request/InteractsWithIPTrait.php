<?php

namespace FluentCart\Framework\Http\Request;

/**
 * Trait InteractsWithIPTrait
 *
 * Resolves the real client IP address securely.
 *
 * SECURITY:
 * - By default, NO proxies are trusted.
 * - Forwarded headers are only trusted if REMOTE_ADDR belongs to a trusted proxy.
 * - Safe for normal hosting, Cloudflare (configured), and explicit proxy setups.
 *
 * EXTENSIBILITY:
 * - Developers can add trusted proxies using the 'trusted_proxies' filter.
 */
trait InteractsWithIPTrait
{
    /**
     * Cached resolved IP for the current request.
     *
     * Instance property (not static) so each Request instance gets its own
     * cache. This is equivalent in production (one instance per HTTP request)
     * but avoids cross-request contamination in tests where multiple dispatches
     * share the same PHP process.
     *
     * @var string|null
     */
    protected $resolvedIp = null;

    /**
     * Get client IP.
     *
     * @param bool $anonymize Return anonymized IP if true.
     * @return string
     */
    public function getIp($anonymize = false)
    {
        if ($this->resolvedIp === null) {
            $this->resolvedIp = $this->resolveIp();
        }

        $ip = $anonymize
            ? wp_privacy_anonymize_ip($this->resolvedIp)
            : $this->resolvedIp;

        return $this->app->applyCustomFilters('user_ip', $ip, $anonymize);
    }

    /**
     * Resolve the real client IP.
     *
     * @return string
     */
    protected function resolveIp()
    {
        if (empty($_SERVER['REMOTE_ADDR'])) {
            return '127.0.0.1'; // CLI or unusual environment
        }

        $remoteAddr = $this->sanitize($_SERVER['REMOTE_ADDR']);

        // Only trust forwarded headers if REMOTE_ADDR is a trusted proxy
        if (!$this->isTrustedProxy($remoteAddr)) {
            return $this->isValidIp($remoteAddr) ? $remoteAddr : '127.0.0.1';
        }

        // Trusted proxy detected → check forwarded headers

        // Cloudflare header
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cfIp = $this->sanitize($_SERVER['HTTP_CF_CONNECTING_IP']);
            if ($this->isValidIp($cfIp)) {
                return $cfIp;
            }
        }

        // X-Forwarded-For header (may contain multiple IPs)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = $this->sanitize($_SERVER['HTTP_X_FORWARDED_FOR']);
            $ips = explode(',', $forwarded);
            foreach ($ips as $ip) {
                $ip = trim($ip);
                if ($this->isValidIp($ip)) {
                    return $ip;
                }
            }
        }

        // X-Real-IP fallback
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $realIp = $this->sanitize($_SERVER['HTTP_X_REAL_IP']);
            if ($this->isValidIp($realIp)) {
                return $realIp;
            }
        }

        // Fallback to REMOTE_ADDR
        return $this->isValidIp($remoteAddr) ? $remoteAddr : '127.0.0.1';
    }

    /**
     * Determine if REMOTE_ADDR belongs to a trusted proxy.
     *
     * @param string $ip
     * @return bool
     */
    protected function isTrustedProxy($ip)
    {
        if (!$this->isValidIp($ip)) {
            return false;
        }

        foreach ($this->getTrustedProxies() as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get an array of trusted proxy CIDR ranges.
     *
     * Reads from config/trustedproxy.php (proxies key) by default.
     * Can be overridden or extended using the filter:
     * add_filter('trusted_proxies', fn($proxies) => [...$proxies, '10.0.0.1']);
     *
     * @return array
     */
    protected function getTrustedProxies()
    {
        $configured = $this->app->config->get('trustedproxy.proxies', []);
        return $this->app->applyCustomFilters('trusted_proxies', $configured);
    }

    /**
     * Validate IPv4 or IPv6 address.
     *
     * @param string $ip
     * @return bool
     */
    protected function isValidIp($ip)
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP);
    }

    /**
     * Sanitize server values (WordPress compatible).
     *
     * @param string $value
     * @return string
     */
    protected function sanitize($value)
    {
        return sanitize_text_field(wp_unslash($value));
    }

    /**
     * Check if an IPv4 address is within a CIDR range.
     *
     * @param string $ip   IP address to check (e.g., "173.245.50.10")
     * @param string $cidr CIDR range (e.g., "173.245.48.0/20")
     * @return bool        True if IP is inside the range, false otherwise
     */
    protected function ipInRange($ip, $cidr)
    {
        // If there is no slash, treat CIDR as a single IP
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        // Split CIDR into subnet and mask length
        list($subnet, $maskLength) = explode('/', $cidr);

        // Convert IP and subnet to long integers (32-bit numbers)
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        // If conversion fails (invalid IP), return false
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        // Create a netmask with $maskLength ones on the left
        // Example: /20 → 11111111.11111111.11110000.00000000
        $mask = -1 << (32 - (int) $maskLength);

        // Apply netmask to subnet to get canonical network address
        // Clears host bits to ensure proper comparison
        $network = $subnetLong & $mask;

        // Apply same mask to target IP and compare with network
        // If equal → IP is inside the CIDR range
        return ($ipLong & $mask) === $network;
    }
}
