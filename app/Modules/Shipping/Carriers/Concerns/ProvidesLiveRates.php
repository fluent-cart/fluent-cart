<?php

namespace FluentCart\App\Modules\Shipping\Carriers\Concerns;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Services\Localization\LocalizationManager;

/**
 * getRates() for carriers that quote from a live API: gating, input guards,
 * caching, error backoff and a per-visitor budget. The carrier implements only
 * fetchLiveRates().
 *
 * Expects AbstractShippingCarrier ($this->settings, isEnabled(), meta()) and an
 * `enable_rates` toggle.
 */
trait ProvidesLiveRates
{
    /**
     * Set by fetchLiveRates() through markLiveRatesPartial() when some of the
     * carrier's services failed transiently, so the answer is cached briefly.
     */
    protected bool $liveRatesPartial = false;

    /** @var array<string, string> Sources the last deferred getRates() left unfetched, id => label. */
    protected array $pendingLiveRateSources = [];

    /**
     * Rates in the shared array format (see CarrierContract::getRates), or a
     * WP_Error carrying ['http_code' => int] (0 = no response) for a failure.
     *
     * @param array  $destination ['country', 'state', 'postcode', 'city'], validated, plus
     *                            'name', 'address_1', 'phone': '' until the shopper filled
     *                            every one checkout shows (AddressHelper::quoteContact()).
     *                            Older cores send no contact keys.
     * @param string $currency    ISO code to price in; return rates in the currency actually priced.
     * @return array[]|\WP_Error
     */
    abstract protected function fetchLiveRates(array $destination, array $items, string $currency);

    /**
     * Whether checkout rates can be priced in $currency. Core's carrier currency
     * policy reads it (AbstractShippingCarrier::canQuoteInCurrency()).
     */
    abstract public function canQuoteInCurrency(string $currency): ?bool;

    /**
     * Cache-key fragment for a carrier that prices the cart; '' when it does not.
     * Not read once liveRatesRequest() returns a request.
     */
    protected function liveRatesCacheItemsKey(array $items): string
    {
        return '';
    }

    /**
     * The API request one quote sends: everything that changes the price, the
     * account or mode included, and nothing that changes on every call (a date,
     * a request id). The cache is keyed on it, so the carrier may read any of
     * $args (e.g. 'form_data') without a stale or shared quote. Null keeps the
     * fetchLiveRates() path and its key.
     *
     * @since 2.4.0
     */
    protected function liveRatesRequest(array $destination, array $items, string $currency, array $args, string $source): ?array
    {
        return null;
    }

    /**
     * Whether this carrier builds its own request, so any checkout field may
     * change its quote. True once liveRatesRequest() is declared outside this trait.
     *
     * @since 2.4.0
     */
    public function buildsLiveRatesRequests(): bool
    {
        return (new \ReflectionMethod($this, 'liveRatesRequest'))->getFileName() !== __FILE__;
    }

    /**
     * Sends the request liveRatesRequest() built. Implement both or neither.
     *
     * @since 2.4.0
     * @return array[]|\WP_Error Same as fetchLiveRates().
     */
    protected function sendLiveRatesRequest(array $request, string $currency, string $source)
    {
        return [];
    }

    /**
     * Live fetches one visitor may trigger per window. Rate endpoints are public.
     */
    protected function liveRatesBudget(): int
    {
        return 20;
    }

    /**
     * Opt in to progressive checkout rates by splitting the quote into sources
     * (e.g. one per connected carrier account) that are fetched and cached apart.
     *
     * @return array<string, string> source id => name shown to the customer; [] = one quote
     */
    protected function liveRateSources(): array
    {
        return [];
    }

    /**
     * One source's rates, called instead of fetchLiveRates() once sources are declared.
     *
     * @return array[]|\WP_Error
     */
    protected function fetchLiveRatesForSource(string $source, array $destination, array $items, string $currency)
    {
        return $this->fetchLiveRates($destination, $items, $currency);
    }

    /**
     * @return array<string, string> Sources the last getRates() left for checkout to fetch.
     */
    public function getPendingRateSources(): array
    {
        return $this->pendingLiveRateSources;
    }

    /**
     * With sources and 'defer_sources' (checkout), answers from caches only and lists
     * the rest as pending; 'fetch_source' may then quote that one source live. Any
     * other caller gets the one unsplit quote, as before.
     */
    public function getRates(array $args): array
    {
        $this->pendingLiveRateSources = [];

        if ($this->settings->get('enable_rates') !== 'yes' || !$this->isEnabled()) {
            return [];
        }

        $country = strtoupper((string) (isset($args['country']) ? $args['country'] : ''));
        if (!$this->isValidCountry($country)) {
            return [];
        }

        // Every field below is visitor-typed and keys the cache, so each is bounded.
        $destination = [
            'country'   => $country,
            'state'     => $this->normalizeState($country, (string) (isset($args['state']) ? $args['state'] : '')),
            'postcode'  => $this->sanitizeAddressText(isset($args['postcode']) ? $args['postcode'] : '', 20),
            'city'      => $this->sanitizeAddressText(isset($args['city']) ? $args['city'] : '', 64),
            'name'      => $this->sanitizeAddressText(isset($args['name']) ? $args['name'] : '', 100),
            'address_1' => $this->sanitizeAddressText(isset($args['address_1']) ? $args['address_1'] : '', 100),
            'phone'     => $this->sanitizeAddressText(isset($args['phone']) ? $args['phone'] : '', 32),
        ];
        $items    = isset($args['items']) && is_array($args['items']) ? $args['items'] : [];
        $currency = $this->requestedCurrency($args);
        $hashBase = implode('|', $destination) . '|' . $currency . '|' . $this->liveRatesCacheItemsKey($items);

        $sources = empty($args['defer_sources']) ? [] : $this->liveRateSources();
        if (!$sources) {
            return (array) $this->quoteLiveRates('', $hashBase, $destination, $items, $currency, $args, true);
        }

        $fetch = isset($args['fetch_source']) && is_string($args['fetch_source']) ? $args['fetch_source'] : '';
        $rates = [];
        foreach ($sources as $source => $label) {
            $source = (string) $source;
            $quoted = $this->quoteLiveRates($source, $hashBase, $destination, $items, $currency, $args, $source === $fetch);
            if ($quoted === null) {
                $this->pendingLiveRateSources[$source] = (string) $label;
                continue;
            }
            foreach ($quoted as $rate) {
                $rate['rate_source'] = $source;
                $rates[] = $rate;
            }
        }

        return $rates;
    }

    /**
     * One quote (a source, or '' for the whole carrier) from its cache, else live.
     *
     * @return array[]|null Null when not cached and $live is false: the source is pending.
     */
    protected function quoteLiveRates(string $source, string $hashBase, array $destination, array $items, string $currency, array $args, bool $live): ?array
    {
        $slug     = $this->meta()['slug'];
        $request  = $this->liveRatesRequest($destination, $items, $currency, $args, $source);
        $hash     = $request === null
            ? md5($hashBase . ($source !== '' ? '|' . $source : ''))
            : md5('request|' . $currency . '|' . $source . '|' . wp_json_encode($request));
        $cacheKey = 'fluent_cart_live_rates_' . $slug . '_' . $hash;
        $quoteKey = 'fluent_cart_live_rates_quote_' . $slug . '_' . $hash;

        // Place Order: the last list quoted here, even one kept only briefly for display.
        if (!empty($args['reuse_quote'])) {
            $quoted = get_transient($quoteKey);
            if (is_array($quoted)) {
                return $this->filterQuotedCurrency($quoted, $currency);
            }
        }

        $cached = get_transient($cacheKey);

        if ($cached === $this->emptyRatesSentinel()) {
            return [];
        }

        if (is_array($cached)) {
            return $this->filterQuotedCurrency($cached, $currency);
        }

        // A recent failure: skip the call so a down API does not slow every checkout.
        if (get_transient($this->liveRatesBackoffKey($source))) {
            return [];
        }

        if (!$live) {
            return null;
        }

        if (!$this->consumeLiveRateBudget()) {
            return [];
        }

        $this->liveRatesPartial = false;
        if ($request !== null) {
            $rates = $this->sendLiveRatesRequest($request, $currency, $source);
        } else {
            $rates = $source === ''
                ? $this->fetchLiveRates($destination, $items, $currency)
                : $this->fetchLiveRatesForSource($source, $destination, $items, $currency);
        }

        if (is_wp_error($rates)) {
            if ($this->isLiveRatesOutage($rates)) {
                set_transient($this->liveRatesBackoffKey($source), 1, MINUTE_IN_SECONDS);
            } else {
                // The carrier refused this address (e.g. a half-typed postcode): skip only it.
                set_transient($cacheKey, $this->emptyRatesSentinel(), min(3 * MINUTE_IN_SECONDS, $this->liveRatesTtl()));
            }
            return [];
        }

        // A partial list (some services rate-limited) is shown only briefly, so the
        // missing services come back; Place Order may still honour it for the full time.
        $ttl        = $this->liveRatesTtl();
        $partialTtl = min(MINUTE_IN_SECONDS, $ttl);
        if ($rates) {
            set_transient($cacheKey, $rates, $this->liveRatesPartial ? $partialTtl : $ttl);
            set_transient($quoteKey, $rates, $ttl);
        } else {
            set_transient($cacheKey, $this->emptyRatesSentinel(), $this->liveRatesPartial ? $partialTtl : min(3 * MINUTE_IN_SECONDS, $ttl));
        }

        return $this->filterQuotedCurrency($rates, $currency);
    }

    /**
     * Seconds a full answer is kept: the carrier's rate cache minutes (filter
     * fluent_cart/shipping/carrier_rate_cache_minutes).
     */
    protected function liveRatesTtl(): int
    {
        return max(1, $this->getRateCacheMinutes()) * MINUTE_IN_SECONDS;
    }

    protected function markLiveRatesPartial(): void
    {
        $this->liveRatesPartial = true;
    }

    /**
     * The currency the caller asked for, else the base currency when none was
     * passed or the carrier can't quote in it.
     */
    protected function requestedCurrency(array $args): string
    {
        $currency = strtoupper(trim((string) (isset($args['currency']) ? $args['currency'] : '')));

        if (!preg_match('/^[A-Z]{3}$/', $currency) || !$this->canQuoteInCurrency($currency)) {
            return $this->baseCurrency();
        }

        return $currency;
    }

    /**
     * Outage or account-level failure (no response, 5xx, auth, rate limit), as
     * opposed to a 4xx about this one destination. Only an outage backs off
     * every visitor.
     */
    protected function isLiveRatesOutage(\WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $code = is_array($data) && isset($data['http_code']) ? (int) $data['http_code'] : 0;

        return $code === 0 || $code >= 500 || in_array($code, [401, 403, 408, 429], true);
    }

    protected function liveRatesBackoffKey(string $source = ''): string
    {
        return 'fluent_cart_live_rates_backoff_' . $this->meta()['slug'] . ($source !== '' ? '_' . md5($source) : '');
    }

    /**
     * Counts one outbound fetch against the visitor (user id, else REMOTE_ADDR).
     * Without an object cache the transient counter is read-modify-write, so a
     * burst of parallel requests can overshoot the budget slightly.
     */
    protected function consumeLiveRateBudget(): bool
    {
        $userId = get_current_user_id();
        $ip     = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        if (!$userId && $ip === '') {
            return true; // CLI / cron: no visitor to limit.
        }

        $identity = $userId ? 'u:' . $userId : 'ip:' . $ip;
        $key      = 'fluent_cart_live_rates_budget_' . md5($this->meta()['slug'] . '|' . $identity);
        $window   = 10 * MINUTE_IN_SECONDS;
        // The budget counts whole quotes; a split quote spends one fetch per source.
        $limit    = $this->liveRatesBudget() * max(1, count($this->liveRateSources()));

        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, 'fluent_cart_pro', $window);
            $used = (int) wp_cache_incr($key, 1, 'fluent_cart_pro');

            return $used <= $limit;
        }

        $state = get_transient($key);
        if (!is_array($state) || (int) (isset($state['until']) ? $state['until'] : 0) <= time()) {
            $state = ['used' => 0, 'until' => time() + $window];
        }

        if ((int) $state['used'] >= $limit) {
            return false;
        }

        $state['used'] = (int) $state['used'] + 1;
        set_transient($key, $state, max(1, (int) $state['until'] - time()));

        return true;
    }

    protected function isValidCountry(string $country): bool
    {
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return false;
        }

        $countries = LocalizationManager::getInstance()->countries();

        return isset($countries[$country]);
    }

    /**
     * Keeps $state only when it is a real subdivision of $country.
     */
    protected function normalizeState(string $country, string $state): string
    {
        if ($state === '') {
            return '';
        }

        $state  = strtoupper($state);
        $states = LocalizationManager::getInstance()->statesOptions($country);
        $codes  = is_array($states) ? array_map('strtoupper', array_column($states, 'value')) : [];

        return in_array($state, $codes, true) ? $state : '';
    }

    /**
     * A visitor-typed address field as plain text of at most $maxLength characters.
     */
    protected function sanitizeAddressText($value, int $maxLength): string
    {
        $value = is_scalar($value) ? trim(sanitize_text_field((string) $value)) : '';

        // Counts characters, not bytes, so a multibyte city name is never cut mid-character.
        return mb_substr($value, 0, $maxLength);
    }

    protected function emptyRatesSentinel(): string
    {
        return 'fluent_cart_live_rates_none';
    }

    /**
     * @return array{country: string, state: string, city: string, postcode: string, address1: string, address2: string}
     */
    protected function buildOrigin(): array
    {
        $store = new StoreSettings();

        return [
            'country'  => (string) $store->get('store_country', ''),
            'state'    => (string) $store->get('store_state', ''),
            'city'     => (string) $store->get('store_city', ''),
            'postcode' => (string) $store->get('store_postcode', ''),
            'address1' => (string) $store->get('store_address1', ''),
            'address2' => (string) $store->get('store_address2', ''),
        ];
    }

    protected function baseCurrency(): string
    {
        return BaseCurrency::get();
    }

    /**
     * Keeps rates in the requested or the base currency; core's currency policy
     * decides which of those show. Any other currency means the carrier's own
     * conversion failed, so it is dropped.
     *
     * @param array[] $rates
     * @return array[]
     */
    protected function filterQuotedCurrency(array $rates, string $currency): array
    {
        $allowed = array_unique([$currency, $this->baseCurrency()]);

        return array_values(array_filter($rates, function ($rate) use ($allowed) {
            return in_array(strtoupper((string) (isset($rate['currency']) ? $rate['currency'] : '')), $allowed, true);
        }));
    }
}
