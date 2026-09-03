<?php

namespace FluentCart\App\Http\Rules;

use Closure;

/**
 * Value rules that apply only once a value is actually supplied.
 *
 * These exist because `nullable` cannot be used alongside a conditional
 * requirement: Validator::filterExcludeables() drops EVERY rule on an attribute
 * — closures included — the moment `nullable` is present and the value is falsy,
 * which silently disarms the requirement sitting beside it. An attribute that
 * must both be demanded under a condition and be shape-checked when present
 * therefore has to express both halves as closures, with no `nullable` on it.
 *
 * Pair with RequiredWhenRule: that one decides whether an empty value is
 * allowed, these decide whether a supplied value is acceptable. Neither fires on
 * the other's territory, so "" and null pass through here untouched and are the
 * requirement's business alone.
 *
 * See dev-docs/framework-update-2.12.6-qa.md finding 9.
 */
class WhenFilledRule
{
    /**
     * Constrain a supplied value to a fixed set. Replaces `in:` on an attribute
     * that cannot carry `nullable`.
     *
     * @param array $allowed
     * @param string $message Already translated.
     * @return \Closure
     */
    public static function in(array $allowed, $message): Closure
    {
        return function ($attribute, $value) use ($allowed, $message) {
            if (!RequiredWhenRule::isFilled($value)) {
                return null;
            }

            return in_array((string) $value, $allowed, true) ? null : $message;
        };
    }

    /**
     * Require a supplied value to be numeric and no smaller than $minimum.
     * Replaces `numeric|min:` on an attribute that cannot carry `nullable`.
     *
     * @param int|float $minimum
     * @param string $notNumericMessage Already translated.
     * @param string $belowMinimumMessage Already translated.
     * @return \Closure
     */
    public static function numericAtLeast($minimum, $notNumericMessage, $belowMinimumMessage): Closure
    {
        return function ($attribute, $value) use ($minimum, $notNumericMessage, $belowMinimumMessage) {
            if (!RequiredWhenRule::isFilled($value)) {
                return null;
            }

            if (!is_numeric($value)) {
                return $notNumericMessage;
            }

            return $value + 0 < $minimum ? $belowMinimumMessage : null;
        };
    }

    /**
     * Require a supplied value to be plain text within $maxLength characters.
     * Replaces `sanitizeText|maxLength:` on an attribute that cannot carry
     * `nullable` — and, unlike those, is never handed a null to trim().
     *
     * @param int $maxLength
     * @param string $message Already translated.
     * @return \Closure
     */
    public static function text($maxLength, $message): Closure
    {
        return function ($attribute, $value) use ($maxLength, $message) {
            if (!RequiredWhenRule::isFilled($value)) {
                return null;
            }

            if (!is_scalar($value)) {
                return $message;
            }

            $value = trim((string) $value);

            if (sanitize_text_field($value) !== $value || strlen($value) > $maxLength) {
                return $message;
            }

            return null;
        };
    }
}
