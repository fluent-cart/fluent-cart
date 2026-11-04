<?php

namespace FluentCart\App\Modules\Shipping\Services;

use FluentCart\Api\Helper;
use FluentCart\App\Modules\Shipping\Carriers\Contracts\CarrierContract;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;

/**
 * Owns the carrier settings write path — whitelisting, secret handling,
 * sanitization, normalization and persistence.
 *
 * Lives here rather than in CarrierController so the controller stays a
 * delegate, and so a non-HTTP caller (WP-CLI, an importer, a test) can save
 * carrier settings through the same guarantees the REST route gets.
 */
class CarrierSettingsService
{
    /**
     * @param  array $input Raw, untrusted key => value pairs.
     * @return array|\WP_Error Masked settings safe to return to a client, or a
     *                         WP_Error the caller should surface as a 422.
     */
    public static function save(CarrierContract $carrier, array $input)
    {
        $declared = $carrier->fields();
        // Nested fields of 'mode' options are saved like top-level ones.
        $fields = CarrierManager::flattenFields($declared);

        $data = static::pluckDeclared($fields, $input);
        $data = static::dropUnchangedSecrets($declared, $data);
        $data = static::dropUnknownModes($fields, $data);

        // Helper::sanitize only exempts type 'password'. A secret carried under any
        // other type — which the contract explicitly allows via 'is_secret' => true —
        // would be run through sanitize_text_field and silently CORRUPTED: percent
        // escapes and angle brackets are stripped, so a token like "tok_a%2Fb" is
        // persisted mangled and the carrier fails to authenticate with no error.
        // Hold the secrets out, sanitize the rest, put them back verbatim.
        $secrets = [];
        foreach (CarrierManager::secretKeys($declared) as $secretKey) {
            if (array_key_exists($secretKey, $data)) {
                // Cast: dropUnchangedSecrets only rejects non-scalars, so a JSON
                // `true` would otherwise persist as a PHP boolean where the carrier
                // expects a credential string.
                $secrets[$secretKey] = (string) $data[$secretKey];
                unset($data[$secretKey]);
            }
        }

        $data = Helper::sanitize($data, array_column($fields, null, 'key'));
        $data = array_merge($data, $secrets);

        // After sanitize (which stringifies booleans) and before the carrier
        // sees the data, so carriers only ever handle the 'yes'/'no' vocabulary.
        $data = CarrierManager::normalizeToggles($declared, $data);

        $data = $carrier->validateSettings($data);
        if (is_wp_error($data)) {
            return $data;
        }

        $carrier->getSettings()->save($data);

        return CarrierManager::maskSettings($declared, $carrier->getSettings()->get());
    }

    /**
     * Restricts input to the carrier's declared field keys — keeps route params
     * (the {slug}), Rest.js's query_timestamp, and any undeclared key a client
     * invents out of the persisted settings.
     */
    protected static function pluckDeclared(array $fields, array $input): array
    {
        return array_intersect_key($input, array_flip(array_column($fields, 'key')));
    }

    /**
     * A 'mode' value must be one of its options. Anything else is dropped, so
     * the stored mode is kept rather than switching to a mode with no fields.
     */
    protected static function dropUnknownModes(array $fields, array $data): array
    {
        foreach ($fields as $field) {
            $key = isset($field['key']) ? $field['key'] : '';
            if ($key === '' || ($field['type'] ?? '') !== 'mode' || !array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if (!is_scalar($value) || !in_array((string) $value, CarrierManager::modeValues($field), true)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * The API returns secrets masked as '', so an empty (or blank) secret means
     * "the admin didn't retype it", not "clear it". Dropping the key here lets
     * BaseCarrierSettings::save()'s merge keep the stored value — a blank form or
     * a partial payload can never destroy a credential.
     *
     * Non-scalars are dropped outright: Helper::sanitize deliberately skips
     * password fields, so without this an array would land in the DB as nested
     * JSON where a string is expected.
     */
    protected static function dropUnchangedSecrets(array $fields, array $data): array
    {
        foreach (CarrierManager::secretKeys($fields) as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if ($value === null || !is_scalar($value) || trim((string) $value) === '') {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
