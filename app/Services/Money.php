<?php

namespace FluentCart\App\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single owner of per-currency decimal knowledge.
 *
 * Storage is ALWAYS cents (value * 100) for every currency, including
 * zero-decimal ones — a ¥7,400 order is stored 740000. Decimals decide
 * display and wire formatting only, never storage scale.
 *
 * Wire formatting and display formatting answer different questions: display
 * decimals follow the currency's local convention (locale-info.php), while a
 * gateway's wire format follows that gateway's own documented table — e.g.
 * TWD displays with 0 decimals but Mollie wants "7400.00". Gateways therefore
 * pass their own zero-decimal override list to formatWireAmount() and never
 * consult the display table.
 */
class Money
{
    /**
     * ISO 4217 three-decimal currencies. Storage (*100) cannot represent
     * their smallest unit, so they are refused at every gateway boundary
     * instead of silently mischarging 10x.
     */
    const THREE_DECIMAL_CURRENCIES = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /**
     * currency => display decimals, derived from locale-info.php.
     * @var array|null
     */
    private static $decimalsMap = null;

    /**
     * @param string $currency ISO 4217 code, any case
     * @return bool
     */
    public static function isThreeDecimal($currency)
    {
        return in_array(strtoupper($currency), self::THREE_DECIMAL_CURRENCIES, true);
    }

    /**
     * Display decimals for a currency (0, 2 or 3). Unknown currencies fall
     * back to 2.
     *
     * @param string $currency ISO 4217 code, any case
     * @return int
     */
    public static function getDecimals($currency)
    {
        $currency = strtoupper($currency);

        if (self::isThreeDecimal($currency)) {
            return 3;
        }

        $map = self::getDecimalsMap();

        return isset($map[$currency]) ? $map[$currency] : 2;
    }

    /**
     * Round a stored amount to a whole unit (a multiple of 100) when the
     * currency has no minor unit. Other currencies get the amount back as is.
     *
     * @param int|float $cents stored amount (always *100)
     * @param string $currency ISO 4217 code, any case
     * @return int|float
     */
    public static function roundIfZeroDecimal($cents, $currency)
    {
        if (self::getDecimals($currency) !== 0) {
            return $cents;
        }

        return (int) (round($cents / 100, 0, PHP_ROUND_HALF_UP) * 100);
    }

    /** Amounts are stored ×100 even when the currency has no minor unit. */
    public static function isWholeAmount($cents, $currency): bool
    {
        $unit = self::getDecimals($currency) === 0 ? 100 : 1;

        return is_numeric($cents) && is_finite((float) $cents)
            && fmod((float) $cents, $unit) === 0.0;
    }

    /**
     * currency => decimals for every currency locale-info.php knows about.
     *
     * @return array
     */
    public static function getDecimalsMap()
    {
        if (self::$decimalsMap !== null) {
            return self::$decimalsMap;
        }

        $map = [];
        $locales = include __DIR__ . '/Localization/i18n/locale-info.php';

        foreach ($locales as $locale) {
            if (isset($locale['currency_code'], $locale['num_decimals'])) {
                $map[strtoupper($locale['currency_code'])] = (int) $locale['num_decimals'];
            }
        }

        foreach (self::THREE_DECIMAL_CURRENCIES as $currency) {
            $map[$currency] = 3;
        }

        self::$decimalsMap = $map;

        return self::$decimalsMap;
    }

    /**
     * Format a stored cents amount as the decimal string a gateway API wants.
     *
     * The wire value is a real-value decimal with 2 decimals for every
     * currency unless the gateway's own documentation says the currency takes
     * none — the caller passes that gateway-specific zero-decimal list.
     *
     * @param int|float $cents stored amount (always *100)
     * @param string $currency ISO 4217 code, any case
     * @param array $gatewayZeroDecimals currencies THIS gateway formats with 0 decimals
     * @return string
     * @throws \Exception for three-decimal currencies — they cannot round-trip through *100 storage
     */
    public static function formatWireAmount($cents, $currency, $gatewayZeroDecimals = [])
    {
        $currency = strtoupper($currency);

        if (self::isThreeDecimal($currency)) {
            throw new \Exception(
                esc_html(sprintf('%s is a three-decimal currency and is not supported for payment processing.', $currency))
            );
        }

        $decimals = in_array($currency, array_map('strtoupper', $gatewayZeroDecimals), true) ? 0 : 2;

        return number_format(floatval($cents) / 100, $decimals, '.', '');
    }

    /**
     * Convert an inbound gateway wire amount to stored cents.
     *
     * A wire amount is a real-value decimal for EVERY currency ("7400" yen,
     * "39.95" pounds) and storage is *100 across the board, so parsing never
     * branches on currency.
     *
     * @param string|int|float $amount
     * @return int cents
     */
    public static function parseWireAmount($amount)
    {
        if (!is_numeric($amount)) {
            return 0;
        }

        return (int) round(floatval($amount) * 100);
    }
}
