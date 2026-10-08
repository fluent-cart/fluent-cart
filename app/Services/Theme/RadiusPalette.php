<?php

namespace FluentCart\App\Services\Theme;

/**
 * The storefront border-radius registry.
 *
 * FluentCart's stylesheets already read three radius tokens with their own
 * fallbacks — `border-radius: var(--fct-btn-radius, <fallback>)` — and nothing
 * declared them. This registry names those tokens once, for every consumer:
 * the setting's sanitiser, the admin's controls, the theme readers and the CSS
 * FrontendTheme prints.
 */
class RadiusPalette
{
    /**
     * The radius roles, keyed by the settings key.
     *
     * Not filterable: each `var` is a token the shipped stylesheets read, and
     * a role added from outside would print a property nothing consumes.
     *
     * @return array Role key => ['var' => custom property, 'label' => label, 'note' => hint].
     */
    public static function roles(): array
    {
        return [
            'card'  => [
                'var'   => '--fct-card-radius',
                'label' => __('Card', 'fluent-cart'),
                'note'  => __('Product cards and their image corners.', 'fluent-cart'),
            ],
            'btn'   => [
                'var'   => '--fct-btn-radius',
                'label' => __('Button', 'fluent-cart'),
                'note'  => __('Add to cart, checkout and the other action buttons.', 'fluent-cart'),
            ],
            'input' => [
                'var'   => '--fct-input-radius',
                'label' => __('Form input', 'fluent-cart'),
                'note'  => __('Text fields, selects, textareas and quantity boxes.', 'fluent-cart'),
            ],
        ];
    }

    /**
     * Normalise one radius length, or refuse it.
     *
     * The grammar is a single length: `0`, or a non-negative number followed
     * by `px`, `rem` or `em`. No percentages, no negatives, no var() or calc():
     * the value is written into a style element on every storefront page, so
     * anything wider would be a way to smuggle CSS in, and a value the grammar
     * cannot read is better left unstated than guessed at.
     *
     * @param mixed $value
     * @return string The normalised length (`0`, `8px`, `0.5rem`), or '' when refused.
     */
    public static function sanitizeLength($value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        $value = strtolower(trim((string)$value));

        if ($value === '0') {
            return '0';
        }

        if (!preg_match('/^(\d+(?:\.\d+)?|\.\d+)(px|rem|em)$/', $value, $matches)) {
            return '';
        }

        return self::formatNumber((float)$matches[1]) . $matches[2];
    }

    /**
     * An owner-typed radius, normalised, or '' when it is not one.
     *
     * A bare number means pixels (`12` → `12px`), the way an owner reads a
     * plain number; a number with a unit is kept with it (`1.5em`, `0.5rem`).
     * Everything else goes through sanitizeLength()'s grammar, so the units
     * are px, rem and em, and nothing negative survives.
     *
     * @param mixed $value
     * @return string
     */
    public static function sanitizeOwnerLength($value): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }

        if (!is_string($value)) {
            return '';
        }

        $value = strtolower(trim($value));

        if (preg_match('/^(\d+(?:\.\d+)?|\.\d+)$/', $value)) {
            $value .= 'px';
        }

        return self::sanitizeLength($value);
    }

    /**
     * Keep only the known roles with a valid owner-typed radius.
     *
     * @param mixed $value Role key => typed radius.
     * @return array Role key => normalised length.
     */
    public static function sanitizeOwnerMap($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $radii = [];

        foreach (array_keys(self::roles()) as $role) {
            if (!array_key_exists($role, $value)) {
                continue;
            }

            $length = self::sanitizeOwnerLength($value[$role]);

            if ($length !== '') {
                $radii[$role] = $length;
            }
        }

        return $radii;
    }

    /**
     * Save-time validation for the owner's typed radii, one rule per role.
     *
     * Each rule runs sanitizeOwnerLength() itself, so a value the sanitiser
     * would drop is refused with a message naming the field, instead of being
     * saved without it. Empty means "not set" and passes.
     *
     * @return array Field path => [rule].
     */
    public static function validationRules(): array
    {
        $rules = [];

        foreach (self::roles() as $role => $definition) {
            $label = $definition['label'];

            $rules['appearance_radius.' . $role] = [
                function ($attribute, $value) use ($label) {
                    if ($value === null || (is_string($value) && trim($value) === '')) {
                        return null;
                    }

                    if (self::sanitizeOwnerLength($value) !== '') {
                        return null;
                    }

                    return sprintf(
                        /* translators: %s: radius field label, e.g. "Button" */
                        __('%s radius: use a number, optionally with px, rem or em.', 'fluent-cart'),
                        $label
                    );
                },
            ];
        }

        return $rules;
    }

    /**
     * A float without trailing zeros, never in exponent form.
     *
     * @param float $number
     * @return string
     */
    protected static function formatNumber(float $number): string
    {
        $formatted = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
