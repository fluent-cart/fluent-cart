<?php

namespace FluentCart\App\Modules\Shipping\Carriers\Core;

use FluentCart\App\Modules\Shipping\Carriers\Contracts\CarrierContract;

class CarrierManager
{
    /**
     * Field types the carrier settings form draws. A 'section' is a heading and a
     * 'webhook_url' places the carrier's webhook URL; neither holds a value. A
     * carrier checks this list before it declares one, since an older form draws
     * an unknown type as a text input.
     */
    const FIELD_TYPES = ['text', 'password', 'toggle', 'select', 'mode', 'section', 'webhook_url', 'status', 'readonly', 'notice', 'webhook_setup'];

    /**
     * Types that only display something and hold no setting.
     */
    const DISPLAY_TYPES = ['section', 'webhook_url', 'status', 'readonly', 'notice', 'webhook_setup'];

    protected static ?CarrierManager $instance = null;
    protected array $carriers = [];

    public static function getInstance(): CarrierManager
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function carrier(string $slug): ?CarrierContract
    {
        return self::getInstance()->get($slug);
    }

    public function register(string $slug, CarrierContract $carrier): void
    {
        $slug = sanitize_key($slug);

        if (!$slug) {
            _doing_it_wrong(__METHOD__, 'Carrier slug must be a non-empty string.', esc_html(FLUENTCART_VERSION));
            return;
        }

        if (isset($this->carriers[$slug])) {
            _doing_it_wrong(
                __METHOD__,
                sprintf('Carrier "%s" is already registered and cannot be overwritten.', esc_html($slug)),
                esc_html(FLUENTCART_VERSION)
            );
            return;
        }

        $carrier->boot();
        $this->carriers[$slug] = $carrier;
    }

    public function get(string $slug): ?CarrierContract
    {
        return isset($this->carriers[$slug]) ? $this->carriers[$slug] : null;
    }

    public function all(): array
    {
        return $this->carriers;
    }

    public function enabled(): array
    {
        return array_filter($this->carriers, function ($carrier) {
            return $carrier->isEnabled();
        });
    }

    /**
     * The Create shipment form's payload: every registered carrier with what it
     * can do for a dispatch. Read through shipping/carrier-options, gated on
     * orders/manage_statuses like the create route, so it carries no settings;
     * getAllMeta() stays behind store/sensitive.
     *
     * Includes DISABLED carriers on purpose. The enable toggle governs what
     * checkout offers, not what an admin may record after the fact — a shipment
     * still has to be recordable against a carrier that was turned off since the
     * order shipped, and ShipmentService accepts any registered carrier to match.
     */
    public function getDispatchOptions(): array
    {
        $options = [];

        foreach ($this->carriers as $slug => $carrier) {
            $meta = $carrier->meta();
            if (empty($meta['name'])) {
                continue;
            }

            $options[] = [
                'slug'            => $slug,
                'name'            => $meta['name'],
                // Recordable but not offered at checkout; the dropdown says so.
                'is_enabled'      => $carrier->isEnabled(),
                // Buys the label and assigns tracking: the form locks those fields.
                'supports_labels' => static::carrierBuysLabels($carrier),
                // Can price the shipment: the form offers a rate picker.
                'supports_rates'  => static::carrierQuotesRates($carrier),
            ];
        }

        return $options;
    }

    /**
     * Enabled, declares 'labels', and the merchant switched labels on. A disabled
     * carrier dispatches nothing, so it must never lock the tracking field.
     */
    protected static function carrierBuysLabels(CarrierContract $carrier): bool
    {
        $meta = $carrier->meta();

        return $carrier->isEnabled()
            && in_array('labels', isset($meta['supports']) ? (array) $meta['supports'] : [], true)
            && $carrier->getSettings()->get('enable_labels') === 'yes';
    }

    /**
     * Enabled and declares 'rates'. A label carrier quotes the rates it would buy,
     * so its label toggle governs both: a rate it cannot then buy is a dead end.
     */
    protected static function carrierQuotesRates(CarrierContract $carrier): bool
    {
        $meta     = $carrier->meta();
        $supports = isset($meta['supports']) ? (array) $meta['supports'] : [];

        if (!$carrier->isEnabled() || !in_array('rates', $supports, true)) {
            return false;
        }

        return in_array('labels', $supports, true) ? static::carrierBuysLabels($carrier) : true;
    }

    /**
     * The fields as one flat list: each 'mode' field followed by its options'
     * fields. Every key-walking path (save, mask, secrets) reads this, so a
     * field nested in a mode option gets the same guarantees as a top-level one.
     * A 'section' heading and a 'webhook_url' entry hold no value and are left out.
     */
    public static function flattenFields(array $fields): array
    {
        $flat = [];

        foreach ($fields as $field) {
            if (!is_array($field) || in_array(isset($field['type']) ? $field['type'] : '', static::DISPLAY_TYPES, true)) {
                continue;
            }

            $flat[] = $field;

            if ((isset($field['type']) ? $field['type'] : '') !== 'mode') {
                continue;
            }

            $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
            foreach ($options as $option) {
                $nested = is_array($option) && isset($option['fields']) && is_array($option['fields']) ? $option['fields'] : [];
                foreach ($nested as $nestedField) {
                    if (is_array($nestedField) && !in_array(isset($nestedField['type']) ? $nestedField['type'] : '', static::DISPLAY_TYPES, true)) {
                        $flat[] = $nestedField;
                    }
                }
            }
        }

        return $flat;
    }

    /**
     * The values a 'mode' field accepts, as strings.
     */
    public static function modeValues(array $field): array
    {
        $values  = [];
        $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];

        foreach ($options as $option) {
            if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                $values[] = (string) $option['value'];
            }
        }

        return $values;
    }

    /**
     * Keys whose values must never be sent to a client: password-type fields,
     * plus anything a carrier explicitly marks with 'is_secret' => true.
     *
     * Single source of truth — the mask path and the save path both need this
     * set, and if the two definitions ever drifted, one would echo a secret the
     * other treats as unchanged.
     */
    public static function secretKeys(array $fields): array
    {
        $keys = [];

        foreach (static::flattenFields($fields) as $field) {
            $key = isset($field['key']) ? $field['key'] : '';
            if ($key === '') {
                continue;
            }

            $type = isset($field['type']) ? $field['type'] : '';
            if ($type === 'password' || !empty($field['is_secret'])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Every registered carrier with its field schema and (masked) settings — the
     * management payload. Dispatch uses getDispatchOptions() instead; it needs a
     * narrower permission and must not see settings.
     */
    public function getAllMeta(): array
    {
        $result = [];
        foreach ($this->carriers as $slug => $carrier) {
            $described = $this->describeCarrier($slug);
            if ($described) {
                $result[] = $described;
            }
        }
        return $result;
    }

    /**
     * One carrier's management payload, as getAllMeta() lists it. Null for an
     * unknown slug or a carrier missing the meta the Vue pages rely on.
     */
    public function describeCarrier(string $slug): ?array
    {
        $carrier = $this->get($slug);
        if (!$carrier) {
            return null;
        }

        $meta   = $carrier->meta();
        $fields = $carrier->fields();

        // Third parties register via filter — assert the minimum contract
        // the Vue pages rely on instead of rendering broken rows.
        if (empty($meta['slug']) || empty($meta['name'])) {
            _doing_it_wrong(
                __METHOD__,
                sprintf('Carrier "%s" meta() must return non-empty "slug" and "name" keys.', esc_html($slug)),
                esc_html(FLUENTCART_VERSION)
            );
            return null;
        }

        // Keys share one settings store, so a key declared twice reads and saves one value.
        $keys = array_filter(array_column(static::flattenFields($fields), 'key'), 'is_string');
        $duplicates = array_unique(array_diff_assoc($keys, array_unique($keys)));
        if ($duplicates) {
            _doing_it_wrong(
                __METHOD__,
                sprintf('Carrier "%s" declares field keys more than once: %s.', esc_html($slug), esc_html(implode(', ', $duplicates))),
                esc_html(FLUENTCART_VERSION)
            );
        }

        $settings = $carrier->getSettings()->get();

        return [
            'slug'          => $slug,
            'meta'          => $meta,
            'fields'        => $fields,
            // Authoritative enablement signal — carriers may override
            // isEnabled() with credential checks beyond the is_active toggle.
            'is_enabled'    => $carrier->isEnabled(),
            'settings'      => static::maskSettings($fields, $settings),
            'saved_secrets' => static::savedSecrets($fields, $settings),
            // Embeds the webhook secret, so it stays on this store/sensitive payload.
            'webhook_url'   => $carrier->getWebhookUrl(),
            'notices'       => static::setupNotices($carrier),
        ];
    }

    /**
     * The carrier's setup notices, well-formed or dropped. Not escaped: Vue
     * renders them as text.
     */
    protected static function setupNotices(CarrierContract $carrier): array
    {
        if (!method_exists($carrier, 'getSetupNotices')) {
            return [];
        }

        try {
            $notices = $carrier->getSetupNotices();
        } catch (\Throwable $e) {
            // A third-party carrier must not take the carrier pages down.
            return [];
        }

        if (!is_array($notices)) {
            return [];
        }

        $valid = [];

        foreach ($notices as $notice) {
            if (!is_array($notice)) {
                continue;
            }

            $type    = isset($notice['type']) && is_string($notice['type']) ? strtolower($notice['type']) : '';
            $message = isset($notice['message']) ? static::noticeText($notice['message']) : '';

            if (!in_array($type, ['warning', 'error', 'info'], true) || $message === '') {
                continue;
            }

            $action = isset($notice['action']) && is_array($notice['action']) ? $notice['action'] : [];
            $label  = isset($action['label']) ? static::noticeText($action['label']) : '';
            $route  = isset($action['route']) ? static::noticeText($action['route']) : '';

            $valid[] = [
                'type'    => $type,
                'message' => $message,
                'action'  => ($label !== '' && $route !== '') ? ['label' => $label, 'route' => $route] : null,
            ];
        }

        return $valid;
    }

    /**
     * A string or number as a string; anything else, or blank text, as ''.
     */
    protected static function noticeText($value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        $value = (string) $value;

        return trim($value) === '' ? '' : $value;
    }

    /**
     * Builds the settings payload for API responses from the carrier's DECLARED
     * fields — never by walking the stored blob. A carrier may persist keys it
     * does not declare (OAuth tokens, webhook secrets, cached error state); those
     * are secrets or internals with no place in a response, and walking the blob
     * emitted them verbatim. Declared-only means they can never leak by omission.
     *
     * Secret fields keep their key (so the UI can still render the input) but
     * return an empty string; the save path reads an empty secret as "unchanged".
     */
    public static function maskSettings(array $fields, array $settings): array
    {
        $secretKeys = static::secretKeys($fields);

        $masked = [];

        foreach (static::flattenFields($fields) as $field) {
            $key = isset($field['key']) ? $field['key'] : '';
            if ($key === '' || !array_key_exists($key, $settings)) {
                continue;
            }

            $masked[$key] = in_array($key, $secretKeys, true) ? '' : $settings[$key];
        }

        return $masked;
    }

    /**
     * The declared secrets that hold a value, so the UI can show them as saved
     * while maskSettings() sends them as ''. Only the last four characters of a
     * long secret are revealed, to tell two tokens apart.
     *
     * @return array<string, array{ends_with: string}>
     */
    public static function savedSecrets(array $fields, array $settings): array
    {
        $saved = [];

        foreach (static::secretKeys($fields) as $key) {
            $value = isset($settings[$key]) && is_scalar($settings[$key]) ? (string) $settings[$key] : '';
            if (trim($value) === '') {
                continue;
            }

            $saved[$key] = [
                'ends_with' => strlen($value) >= 16 ? substr($value, -4) : '',
            ];
        }

        return $saved;
    }

    /**
     * Coerces toggle values into the 'yes'/'no' vocabulary carriers store.
     *
     * Helper::sanitize has no toggle sanitizer, so a JSON boolean reaches a
     * carrier as sanitize_text_field(true) === '1' — which is not 'yes', so a
     * client asking to ENABLE a carrier silently disabled it. Normalizing here
     * means every carrier, including third-party ones, gets the coercion instead
     * of each re-implementing an `=== 'yes'` check.
     */
    public static function normalizeToggles(array $fields, array $settings): array
    {
        foreach (static::flattenFields($fields) as $field) {
            $key  = isset($field['key']) ? $field['key'] : '';
            $type = isset($field['type']) ? $field['type'] : '';

            if ($type !== 'toggle' || $key === '' || !array_key_exists($key, $settings)) {
                continue;
            }

            // FILTER_VALIDATE_BOOLEAN accepts both the stored vocabulary
            // ('yes'/'no') and what a JSON client sends (true/false, '1'/'').
            $settings[$key] = filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';
        }

        return $settings;
    }
}
