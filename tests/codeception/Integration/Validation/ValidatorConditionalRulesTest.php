<?php

namespace Tests\Integration\Validation;

use FluentCart\App\Http\Rules\RequiredWhenRule;
use FluentCart\App\Http\Rules\WhenFilledRule;
use FluentCart\Framework\Validator\Validator;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * A conditional requirement must not cost the value rules beside it.
 *
 * Validator::filterRequiredIf() returns [] for an unmet `required_if` condition,
 * discarding every other rule on the attribute — `in:`, `numeric`, `min:` and
 * closures alike — so junk values sailed through. RequiredWhenRule carries the
 * same condition as a closure, which leaves the rule set untouched.
 */
class ValidatorConditionalRulesTest extends WPTestCase
{
    public function test_the_framework_rule_still_drops_every_other_rule(): void
    {
        // Pins the defect this rule exists to route around. If a framework bump
        // ever fixes filterRequiredIf(), this fails and RequiredWhenRule can be
        // reconsidered — it must not go on being justified by a premise that
        // silently stopped being true.
        $validator = Validator::make(
            ['payment_type' => 'onetime', 'repeat_interval' => 'BOGUS_INTERVAL'],
            ['repeat_interval' => 'required_if:payment_type,subscription|in:yearly,monthly']
        );

        $this->assertFalse(
            $validator->validate()->fails(),
            'the framework still discards `in:` beside an unmet required_if — if this now fails, the framework has been fixed and RequiredWhenRule is no longer needed'
        );
    }

    public function test_value_rules_survive_an_unmet_condition(): void
    {
        $validator = Validator::make(
            ['payment_type' => 'onetime', 'repeat_interval' => 'BOGUS_INTERVAL'],
            ['repeat_interval' => [
                RequiredWhenRule::make('payment_type', 'subscription', 'Interval is required.'),
                'in:yearly,monthly',
            ]]
        );

        $validator->validate();

        $this->assertArrayHasKey(
            'in',
            $validator->errors()['repeat_interval'] ?? [],
            'a one-time row must still be held to the interval enum — the requirement is what does not apply, the in: list always does'
        );
    }

    public function test_numeric_bounds_survive_an_unmet_condition(): void
    {
        $validator = Validator::make(
            ['manage_setup_fee' => 'no', 'signup_fee' => -500],
            ['signup_fee' => [
                RequiredWhenRule::make('manage_setup_fee', 'yes', 'Setup Fee Amount is required.'),
                'numeric',
                'min:0',
            ]]
        );

        $validator->validate();

        $this->assertArrayHasKey(
            'min',
            $validator->errors()['signup_fee'] ?? [],
            'a negative setup fee must be rejected whether or not the setup fee is switched on — money rules are not conditional'
        );
    }

    public function test_the_requirement_fires_when_the_condition_is_met(): void
    {
        $validator = Validator::make(
            ['manage_stock' => 1],
            ['total_stock' => [RequiredWhenRule::make('manage_stock', '1', 'Stock quantity is required.')]]
        );

        $validator->validate();

        $this->assertContains(
            'Stock quantity is required.',
            array_values($validator->errors()['total_stock'] ?? []),
            'an absent value must still be demanded when the condition is met — closures run before the presence check, which is what makes this work at all'
        );
    }

    public function test_the_requirement_stays_quiet_when_the_condition_is_unmet(): void
    {
        $validator = Validator::make(
            ['manage_stock' => 0],
            ['total_stock' => [RequiredWhenRule::make('manage_stock', '1', 'Stock quantity is required.')]]
        );

        $this->assertFalse(
            $validator->validate()->fails(),
            'inventory tracking off must leave the stock counters alone — this is the regression findings 1-4 came from'
        );
    }

    public function test_an_empty_string_counts_as_missing(): void
    {
        $validator = Validator::make(
            ['manage_stock' => 1, 'total_stock' => '   '],
            ['total_stock' => [RequiredWhenRule::make('manage_stock', '1', 'Stock quantity is required.')]]
        );

        $this->assertTrue(
            $validator->validate()->fails(),
            'emptiness must match ValidatesAttributes::validateRequired(), which trims strings before deciding'
        );
    }

    public function test_nullable_still_swallows_a_conditional_requirement(): void
    {
        // The finding-9 defect, pinned. filterExcludeables() drops every rule on
        // an attribute the moment `nullable` meets a falsy value — before the
        // condition is ever consulted — so a `nullable|required_if` pair demands
        // nothing at all. If a framework bump fixes this, the test fails and says
        // so, rather than leaving WhenFilledRule justified by a stale premise.
        $validator = Validator::make(
            ['payment_type' => 'subscription', 'repeat_interval' => ''],
            ['repeat_interval' => 'nullable|required_if:payment_type,subscription']
        );

        $this->assertFalse(
            $validator->validate()->fails(),
            'the framework still lets `nullable` disarm a conditional requirement — if this now fails, finding 9 has been fixed upstream and these closures can be reconsidered'
        );
    }

    public function test_the_closure_pair_demands_a_value_the_condition_requires(): void
    {
        $validator = Validator::make(
            ['payment_type' => 'subscription', 'repeat_interval' => ''],
            ['repeat_interval' => [
                RequiredWhenRule::make('payment_type', 'subscription', 'Interval is required for subscriptions.'),
                WhenFilledRule::in(['yearly', 'monthly'], 'Interval must be a valid frequency.'),
            ]]
        );

        $validator->validate();

        $this->assertContains(
            'Interval is required for subscriptions.',
            array_values($validator->errors()['repeat_interval'] ?? []),
            'an empty interval on a subscription must be demanded — this is what `nullable` was swallowing'
        );
    }

    public function test_the_closure_pair_ignores_an_empty_value_the_condition_does_not_require(): void
    {
        $validator = Validator::make(
            ['payment_type' => 'onetime', 'repeat_interval' => ''],
            ['repeat_interval' => [
                RequiredWhenRule::make('payment_type', 'subscription', 'Interval is required for subscriptions.'),
                WhenFilledRule::in(['yearly', 'monthly'], 'Interval must be a valid frequency.'),
            ]]
        );

        $this->assertFalse(
            $validator->validate()->fails(),
            'an empty interval on a one-time row is normal stored state and must still pass — dropping `nullable` must not cost that'
        );
    }

    public function test_when_filled_rules_leave_empty_and_absent_values_alone(): void
    {
        $rules = [
            'enum'    => [WhenFilledRule::in(['yes', 'no'], 'bad enum')],
            'amount'  => [WhenFilledRule::numericAtLeast(0, 'not a number', 'below zero')],
            'label'   => [WhenFilledRule::text(10, 'bad text')],
        ];

        $this->assertFalse(
            Validator::make(['enum' => '', 'amount' => '', 'label' => ''], $rules)->validate()->fails(),
            'empty strings are the requirement rule\'s business, not these'
        );
        $this->assertFalse(
            Validator::make(['enum' => null, 'amount' => null, 'label' => null], $rules)->validate()->fails(),
            'a null must not reach trim() or is_numeric() as a failure — `nullable` used to guard that'
        );
        $this->assertFalse(
            Validator::make([], $rules)->validate()->fails(),
            'an absent key must stay unvalidated'
        );
    }

    public function test_when_filled_rules_reject_a_supplied_bad_value(): void
    {
        $validator = Validator::make(
            ['enum' => 'BOGUS', 'amount' => -5, 'label' => 'way too long to fit'],
            [
                'enum'   => [WhenFilledRule::in(['yes', 'no'], 'bad enum')],
                'amount' => [WhenFilledRule::numericAtLeast(0, 'not a number', 'below zero')],
                'label'  => [WhenFilledRule::text(10, 'bad text')],
            ]
        );

        $validator->validate();
        $errors = $validator->errors();

        $this->assertContains('bad enum', array_values($errors['enum'] ?? []), 'a value outside the set must be rejected');
        $this->assertContains('below zero', array_values($errors['amount'] ?? []), 'a negative amount must be rejected');
        $this->assertContains('bad text', array_values($errors['label'] ?? []), 'an over-long label must be rejected');
    }

    public function test_the_condition_is_read_per_row(): void
    {
        $validator = Validator::make(
            ['variants' => [
                ['other_info' => ['payment_type' => 'subscription']],
                ['other_info' => ['payment_type' => 'onetime']],
            ]],
            ['variants.*.other_info.repeat_interval' => [
                RequiredWhenRule::make('variants.*.other_info.payment_type', 'subscription', 'Interval is required.'),
            ]]
        );

        $validator->validate();
        $errors = $validator->errors();

        $this->assertArrayHasKey(
            'variants.0.other_info.repeat_interval',
            $errors,
            'the subscription row must be flagged for its missing interval'
        );
        $this->assertArrayNotHasKey(
            'variants.1.other_info.repeat_interval',
            $errors,
            'the one-time row in the same payload must not be — the framework decided this once for all rows, keeping or dropping the rules wholesale'
        );
    }

    public function test_the_condition_is_read_past_the_tenth_row(): void
    {
        $variants = [];
        for ($row = 0; $row <= 10; $row++) {
            $variants[$row] = ['other_info' => ['payment_type' => 'subscription']];
        }

        $validator = Validator::make(
            ['variants' => $variants],
            ['variants.*.other_info.repeat_interval' => [
                RequiredWhenRule::make('variants.*.other_info.payment_type', 'subscription', 'Interval is required.'),
            ]]
        );

        $validator->validate();

        $this->assertArrayHasKey(
            'variants.10.other_info.repeat_interval',
            $validator->errors(),
            'row 10 must be checked like every other row — validateRequiredIf() resolved the wildcard with a single-digit pattern and silently passed everything from here on (finding 10)'
        );
    }
}
