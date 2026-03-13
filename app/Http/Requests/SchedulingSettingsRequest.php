<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class SchedulingSettingsRequest extends RequestGuard
{
    public function beforeValidation(): array
    {
        $intFields = [
            'yearly_renewal_reminder_days',
            'trial_end_reminder_days',
            'monthly_renewal_reminder_days',
            'quarterly_renewal_reminder_days',
            'half_yearly_renewal_reminder_days',
        ];

        $data = [];

        foreach ($intFields as $field) {
            $value = $this->request->get($field);

            if ($value !== null && $value !== '' && ctype_digit(trim((string) $value))) {
                $data[$field] = (int) trim((string) $value);
            }
        }

        return $data;
    }

    public function rules(): array
    {
        $rules = [
            'reminders_enabled'                    => 'nullable|sanitizeText',
            'yearly_renewal_reminders_enabled'     => 'nullable|sanitizeText',
            'yearly_renewal_reminder_days'         => 'nullable',
            'trial_end_reminders_enabled'          => 'nullable|sanitizeText',
            'trial_end_reminder_days'              => 'nullable',
            'monthly_renewal_reminders_enabled'    => 'nullable|sanitizeText',
            'monthly_renewal_reminder_days'        => 'nullable',
            'quarterly_renewal_reminders_enabled'  => 'nullable|sanitizeText',
            'quarterly_renewal_reminder_days'      => 'nullable',
            'half_yearly_renewal_reminders_enabled' => 'nullable|sanitizeText',
            'half_yearly_renewal_reminder_days'    => 'nullable',
        ];

        // Only validate days fields when their corresponding toggle is enabled
        $conditionalRules = [
            'yearly_renewal_reminders_enabled'     => ['yearly_renewal_reminder_days' => 'integer|min:7|max:90'],
            'trial_end_reminders_enabled'          => ['trial_end_reminder_days' => 'integer|min:1|max:14'],
            'monthly_renewal_reminders_enabled'    => ['monthly_renewal_reminder_days' => 'integer|min:3|max:28'],
            'quarterly_renewal_reminders_enabled'  => ['quarterly_renewal_reminder_days' => 'integer|min:7|max:60'],
            'half_yearly_renewal_reminders_enabled' => ['half_yearly_renewal_reminder_days' => 'integer|min:7|max:60'],
        ];

        foreach ($conditionalRules as $toggle => $daysRules) {
            if ($this->request->get($toggle) === 'yes') {
                $rules = array_merge($rules, $daysRules);
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'yearly_renewal_reminder_days.integer'         => __('Yearly renewal reminder days must be a whole number.', 'fluent-cart'),
            'yearly_renewal_reminder_days.min'             => __('Yearly renewal reminder days must be at least 7.', 'fluent-cart'),
            'yearly_renewal_reminder_days.max'             => __('Yearly renewal reminder days must not exceed 90.', 'fluent-cart'),
            'trial_end_reminder_days.integer'              => __('Trial end reminder days must be a whole number.', 'fluent-cart'),
            'trial_end_reminder_days.min'                  => __('Trial end reminder days must be at least 1.', 'fluent-cart'),
            'trial_end_reminder_days.max'                  => __('Trial end reminder days must not exceed 14.', 'fluent-cart'),
            'monthly_renewal_reminder_days.integer'        => __('Monthly renewal reminder days must be a whole number.', 'fluent-cart'),
            'monthly_renewal_reminder_days.min'            => __('Monthly renewal reminder days must be at least 3.', 'fluent-cart'),
            'monthly_renewal_reminder_days.max'            => __('Monthly renewal reminder days must not exceed 28.', 'fluent-cart'),
            'quarterly_renewal_reminder_days.integer'      => __('Quarterly renewal reminder days must be a whole number.', 'fluent-cart'),
            'quarterly_renewal_reminder_days.min'          => __('Quarterly renewal reminder days must be at least 7.', 'fluent-cart'),
            'quarterly_renewal_reminder_days.max'          => __('Quarterly renewal reminder days must not exceed 60.', 'fluent-cart'),
            'half_yearly_renewal_reminder_days.integer'    => __('Half yearly renewal reminder days must be a whole number.', 'fluent-cart'),
            'half_yearly_renewal_reminder_days.min'        => __('Half yearly renewal reminder days must be at least 7.', 'fluent-cart'),
            'half_yearly_renewal_reminder_days.max'        => __('Half yearly renewal reminder days must not exceed 60.', 'fluent-cart'),
        ];
    }

    public function sanitize(): array
    {
        return [
            'reminders_enabled'                    => 'sanitize_text_field',
            'yearly_renewal_reminders_enabled'     => 'sanitize_text_field',
            'yearly_renewal_reminder_days'         => 'intval',
            'trial_end_reminders_enabled'          => 'sanitize_text_field',
            'trial_end_reminder_days'              => 'intval',
            'monthly_renewal_reminders_enabled'    => 'sanitize_text_field',
            'monthly_renewal_reminder_days'        => 'intval',
            'quarterly_renewal_reminders_enabled'  => 'sanitize_text_field',
            'quarterly_renewal_reminder_days'      => 'intval',
            'half_yearly_renewal_reminders_enabled' => 'sanitize_text_field',
            'half_yearly_renewal_reminder_days'    => 'intval',
        ];
    }
}
