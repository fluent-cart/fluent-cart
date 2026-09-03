<?php

namespace FluentCart\App\Http\Rules;

use Closure;
use Countable;
use FluentCart\Framework\Support\Arr;

/**
 * A closure replacement for the `required_if` rule string.
 *
 * `Validator::filterRequiredIf()` discards EVERY rule on an attribute whose
 * `required_if` condition is unmet — `in:`, `numeric`, `min:` and closures
 * alike — so a rule set that carried both a conditional requirement and value
 * constraints silently lost the value constraints exactly when the condition
 * did not apply. A closure carries the same condition without that side
 * effect: with no `required_if` string left on the attribute,
 * filterRequiredIf() returns the rule set untouched and the value rules keep
 * running.
 *
 * Closures are also invoked before the presence check in
 * Validator::validateAttribute(), so this still fires for an attribute the
 * request never sent — which is what `required_if` did.
 *
 * Emptiness and the condition match mirror ValidatesAttributes::
 * validateRequired() and validateRequiredIf() so the swap is behaviour-for-
 * behaviour, with one deliberate exception: the wildcard in $otherField is
 * resolved for every row, where the framework's `/\.\d\./` matched only
 * single-digit indexes and quietly gave up from row 10 on.
 *
 * See dev-docs/framework-update-2.12.6-qa.md findings 5 and 10.
 */
class RequiredWhenRule
{
    /**
     * Build the rule closure.
     *
     * @param string $otherField Dot path of the field the condition reads. May
     *                           contain `*`, resolved against the attribute
     *                           being validated.
     * @param string|array $expectedValues Value(s) that make the field required.
     * @param string $message Error message, already translated.
     * @return \Closure
     */
    public static function make($otherField, $expectedValues, $message): Closure
    {
        $expectedValues = (array) $expectedValues;

        return function ($attribute, $value, $rules, $data) use ($otherField, $expectedValues, $message) {
            $path = static::resolvePath($otherField, $attribute);

            if ($path === null) {
                return null;
            }

            $otherValue = Arr::get($data, $path);

            if (!in_array($otherValue, static::matchTypeOf($otherValue, $expectedValues))) {
                return null;
            }

            return static::isFilled($value) ? null : $message;
        };
    }

    /**
     * Substitute each `*` in the condition path with the row index the
     * attribute under validation carries at the same position.
     *
     * @param string $path
     * @param string $attribute
     * @return string|null Null when the row index cannot be resolved.
     */
    protected static function resolvePath($path, $attribute)
    {
        if (strpos($path, '*') === false) {
            return $path;
        }

        $pathSegments = explode('.', $path);
        $attributeSegments = explode('.', $attribute);

        foreach ($pathSegments as $index => $segment) {
            if ($segment !== '*') {
                continue;
            }

            $rowIndex = Arr::get($attributeSegments, $index);

            if ($rowIndex === null || !is_numeric($rowIndex)) {
                return null;
            }

            $pathSegments[$index] = $rowIndex;
        }

        return implode('.', $pathSegments);
    }

    /**
     * Mirror validateRequiredIf(): compare as booleans when the other field
     * holds one, so 'true' / 'false' in a rule definition still match.
     *
     * @param mixed $otherValue
     * @param array $expectedValues
     * @return array
     */
    protected static function matchTypeOf($otherValue, array $expectedValues): array
    {
        if (!is_bool($otherValue)) {
            return $expectedValues;
        }

        return array_map(function ($expected) {
            if ($expected === 'true') {
                return true;
            }

            if ($expected === 'false') {
                return false;
            }

            return $expected;
        }, $expectedValues);
    }

    /**
     * Mirror validateRequired(). Public so WhenFilledRule can draw the same
     * empty/filled line this rule does — the two are complementary halves of one
     * attribute's validation and must agree on where that line sits.
     *
     * @param mixed $value
     * @return bool
     */
    public static function isFilled($value): bool
    {
        if (is_null($value)) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if ((is_array($value) || $value instanceof Countable) && count($value) < 1) {
            return false;
        }

        return true;
    }
}
