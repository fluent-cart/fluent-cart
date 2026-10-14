<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\App\Helpers\CurrenciesHelper;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * [fluent_cart_currency_switcher] — a plain <select> that reloads the page
 * with ?currency=XXX. Renders nothing unless more than one currency can be
 * offered.
 */
class CurrencySwitcher
{
    const MENU_TRIGGER_CLASS = 'fct-currency-switcher-toggle';

    public static function hasChoice(): bool
    {
        return count(CurrencyContext::selectableCurrencies()) > 1;
    }

    /**
     * A menu item classed as the dropdown trigger is a dead "#" link while
     * there is nothing to switch to (Pro inactive, or only the base has a
     * rate). Its submenu goes too, or WordPress would lift it to the top level.
     *
     * @param array $items
     * @return array
     */
    public static function removeIdleMenuTriggers($items)
    {
        if (!is_array($items)) {
            return $items;
        }

        $dropped = [];
        foreach ($items as $item) {
            if (is_object($item) && in_array(self::MENU_TRIGGER_CLASS, (array) ($item->classes ?? []), true)) {
                $dropped[(int) $item->ID] = true;
            }
        }

        if (!$dropped || self::hasChoice()) {
            return $items;
        }

        do {
            $found = false;
            foreach ($items as $item) {
                $parentId = (int) ($item->menu_item_parent ?? 0);
                if (!isset($dropped[(int) $item->ID]) && $parentId && isset($dropped[$parentId])) {
                    $dropped[(int) $item->ID] = true;
                    $found = true;
                }
            }
        } while ($found);

        return array_filter($items, function ($item) use ($dropped) {
            return !isset($dropped[(int) $item->ID]);
        });
    }

    /**
     * @param array|string $atts
     */
    public static function render($atts = []): string
    {
        $currencies = CurrencyContext::selectableCurrencies();

        if (count($currencies) < 2) {
            return '';
        }

        $current = CurrencyContext::current();

        /**
         * Replace the switcher's markup (shortcode and block alike). Return a
         * string to use it instead of the default <select>.
         *
         * @param string|null $html
         * @param array       $context ['atts' => array, 'currencies' => string[], 'current' => string]
         */
        $html = apply_filters('fluent_cart/currency_switcher/render', null, [
            'atts'       => is_array($atts) ? $atts : [],
            'currencies' => $currencies,
            'current'    => $current,
        ]);
        if (is_string($html)) {
            return $html;
        }

        $options = '';
        foreach ($currencies as $code) {
            $options .= sprintf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($code),
                selected($code, $current, false),
                esc_html(trim($code . ' ' . html_entity_decode(CurrenciesHelper::getCurrencySign($code))))
            );
        }

        $selectId = wp_unique_id('fct-currency-switcher-');

        return sprintf(
            '<form class="fct-currency-switcher" method="get">%5$s<label class="screen-reader-text" for="%6$s">%1$s</label><select id="%6$s" name="%2$s" onchange="this.form.submit()">%3$s</select><noscript><button type="submit">%4$s</button></noscript></form>',
            esc_html__('Currency', 'fluent-cart'),
            esc_attr(CurrencyContext::QUERY_PARAM),
            $options,
            esc_html__('Change', 'fluent-cart'),
            self::keptQueryFields(),
            esc_attr($selectId)
        );
    }

    /**
     * A GET form replaces the whole query string; carry the page's own
     * (an instant-checkout or re-pay cart hash, ?page_id=, shop filters) so
     * switching currency stays on the same page.
     */
    private static function keptQueryFields(): string
    {
        $fields = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- echoes the page's own query string back as form fields
        foreach (self::flattenQuery(wp_unslash($_GET)) as $name => $value) {
            if ($name === CurrencyContext::QUERY_PARAM) {
                continue;
            }
            $fields .= sprintf('<input type="hidden" name="%1$s" value="%2$s"/>', esc_attr($name), esc_attr($value));
        }

        return $fields;
    }

    /**
     * filters[price_range_from]=10 style keys, one field per leaf value.
     */
    private static function flattenQuery(array $query, string $prefix = ''): array
    {
        $flat = [];
        foreach ($query as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                $flat += self::flattenQuery($value, $name);
            } elseif (is_scalar($value)) {
                $flat[$name] = sanitize_text_field((string) $value);
            }
        }

        return $flat;
    }
}
