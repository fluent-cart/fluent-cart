<?php

namespace FluentCart\Framework\Support;

class Locale
{
    /**
     * Locale identifier.
     * 
     * @var string
     */
    protected $locale = 'en_US';

    /**
     * Locale info.
     * 
     * @var \stdClass
     */
    protected $localeInfo = null;

    /**
     * English month names for keys.
     * @var array
     */
    protected static $monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December',
    ];

    /**
     * English weekday names for keys.
     * @var array
     */
    protected static $weekdayNames = [
        'Sunday', 'Monday', 'Tuesday', 'Wednesday',
        'Thursday', 'Friday', 'Saturday'
    ];

    /**
     * Construct the class.
     * 
     * @param int|string|null $userIdOrLocale
     */
    public function __construct($userIdOrLocale = null)
    {
        $this->localeInfo = $this->populateLocaleInfo($userIdOrLocale);
    }

    /**
     * Load the locale info of a user.
     * 
     * @param  int|null $userIdOrLocale
     * @return \stdClass
     */
    protected function populateLocaleInfo($userIdOrLocale = null)
    {
        if ($userIdOrLocale) {
            if (is_int($userIdOrLocale)) {
                $this->locale = get_user_locale($userIdOrLocale);
            } elseif (is_string($userIdOrLocale)) {
                $this->locale = $userIdOrLocale;
            }
        } else {
            $this->locale = get_user_locale(get_current_user_id());
        }

        $didSwitch = false;
        $originalLocale = get_locale();

        if ($originalLocale !== $this->locale) {
            switch_to_locale($this->locale);
            $didSwitch = true;
        }

        try {
            $userLocale = new \stdClass();

            $wpLocale = $GLOBALS['wp_locale'];

            // Weekdays
            $userLocale->weekday = $wpLocale->weekday;

            // Rebuild array with weekday names as keys
            $userLocale->weekday_initial = array_combine(
                static::$weekdayNames, $wpLocale->weekday_initial
            );

            // Rebuild array with weekday names as keys
            $userLocale->weekday_abbrev = array_combine(
                static::$weekdayNames, $wpLocale->weekday_abbrev
            );

            // Months with exact keys (01-12)
            $monthNumbers = array_keys($wpLocale->month);

            $userLocale->month = array_combine(
                $monthNumbers, $wpLocale->month
            );

            $userLocale->month_genitive = array_combine(
                $monthNumbers, $wpLocale->month_genitive
            );

            // Rebuild month_abbrev with english month names as keys
            $userLocale->month_abbrev = array_combine(
                static::$monthNames, $wpLocale->month_abbrev
            );

            // Meridiem
            $userLocale->meridiem = [
                'am' => $wpLocale->meridiem['am'],
                'pm' => $wpLocale->meridiem['pm'],
                'AM' => $wpLocale->meridiem['AM'],
                'PM' => $wpLocale->meridiem['PM'],
            ];

            // Text Direction and other locale-specific settings
            $userLocale->text_direction = $wpLocale->text_direction;
            $userLocale->number_format = $wpLocale->number_format;
            $userLocale->list_item_separator = $wpLocale->list_item_separator;
            $userLocale->word_count_type = $wpLocale->word_count_type;
            $userLocale->start_day_of_week = get_option('start_of_week');

            $userLocale->id = $this->locale;

            $userLocale->mappings = [
                // Add the mapping of month index to number
                'month_index_to_num' => array_combine(
                    array_keys(static::$monthNames),
                    array_map('intval', $monthNumbers)
                ),
                // Add the english names
                'month' => static::$monthNames,
                'weekday' => static::$weekdayNames
            ];

            return $userLocale;
        } finally {
            if ($didSwitch) {
                restore_previous_locale();
            }
        }
    }

    /**
     * Switch the locale for the user.
     * 
     * @param  string $locale
     * @return void
     */
    public function switch($locale)
    {
        $original = get_locale();
        
        $this->localeInfo = static::getInfo(
            $this->locale = $locale
        );

        switch_to_locale($original);
    }

    /**
     * Restore to the user's original locale.
     * 
     * @return void
     */
    public function restore()
    {
        $original = get_locale();
        
        $this->localeInfo = static::getInfo(
            $this->locale = get_user_locale()
        );

        switch_to_locale($original);
    }

    /**
     * Get specific weekday.
     * 
     * @param  int $day 0-6
     * @return string
     */
    public function getWeekday($day)
    {
        if (is_string($day)) {
            $day = Str::title($day);
            if (strlen($day) === 3) {
                $day = Arr::startsLike(static::$weekdayNames, $day);
                $day = key($day);
            } else {
                $day = array_search($day, static::$weekdayNames);
            }
        }

        // Validate numeric day is within valid range 0-6
        if (!is_int($day) || $day < 0 || $day > 6) {
            return '';
        }

        return $this->localeInfo->weekday[$day] ?? '';
    }
    /**
     * Get specific weekday.
     * 
     * @param  int $day 0-6
     * @return string
     */
    public function getWeekdayName($day)
    {
        return $this->getWeekday($day);
    }

    /**
     * Get weekday initials (Short form).
     * 
     * @param  string $day
     * @return string
     */
    public function getWeekdayInitial($day)
    {
        $day = $this->resolveWeekdayKey($day);

        return $this->localeInfo->weekday_initial[$day] ?? '';
    }

    /**
     * Get weekday abbr (Short form).
     * 
     * @param  string $day
     * @return string
     */
    public function getWeekdayAbbrev($day)
    {
        $day = $this->resolveWeekdayKey($day);
        return $this->localeInfo->weekday_abbrev[$day] ?? '';
    }

    /**
     * Get specific month.
     * 
     * @param  int $month 0-11
     * @return string
     */
    public function getMonth($month)
    {
        $month = $this->resolveMonthNumber($month);

        if (!$month) {
            return '';
        }

        $key = $month < 10 ? '0' . $month : (string) $month;

        return $this->localeInfo->month[$key] ?? '';
    }

    /**
     * Get specific month.
     * 
     * @param  int $month 0-11
     * @return string
     */
    public function getMonthName($month)
    {
        return $this->getMonth($month);
    }

    /**
     * Get specific month.
     * 
     * @param  int $month 01-12
     * @return string
     */
    public function getShortMonthName($month)
    {
        return $this->getMonthAbbrev($month);
    }

    /**
     * Get specific genitive month.
     * 
     * @param  int $month 01-12
     * @return string
     */
    public function getMonthGenitive($month)
    {
        $month = $this->resolveMonthNumber($month);
    	return $this->localeInfo->month_genitive[$month] ?? '';
    }

    /**
     * Get month abbr (Short form).
     * 
     * @param  string $month
     * @return string
     */
    public function getMonthAbbrev($month)
    {
        $month = $this->resolveMonthName($month);
        return $this->localeInfo->month_abbrev[$month] ?? '';
    }

    /**
     * Get meridiem.
     * 
     * @param  string $period
     * @return string
     */
    public function getMeridiem($period)
    {
        return $this->localeInfo->meridiem[$period] ?? '';
    }

    /**
     * Get tetx direction.
     * 
     * @return string
     */
    public function getTextDirection()
    {
        return $this->localeInfo->text_direction ?? 'ltr';
    }

    /**
     * Get number format.
     * 
     * @return string
     */
    public function getNumberFormat()
    {
        return $this->localeInfo->number_format ?? '';
    }

    /**
     * Get list item separator.
     * 
     * @return string
     */
    public function getListItemSeparator()
    {
        return $this->localeInfo->list_item_separator ?? '';
    }

    /**
     * Get word count type.
     * 
     * @return string
     */
    public function getWordCountType()
    {
        return $this->localeInfo->word_count_type ?? '';
    }

    /**
     * Get start day number/name of the week
     * 
     * @return int
     */
    public function getStartDayOfWeek($name = false)
    {
        $num = $this->localeInfo->startDayOfWeek ?? 1;

        if ($name) {
            return static::$weekdayNames[$num] ?? '';
        }

        return $num;
    }

    /**
     * Get start day name of the week
     * 
     * @return int
     */
    public function getStartDayNameOfWeek()
    {
        return $this->getStartDayOfWeek(true);
    }

    /**
	 * Checks if current locale is RTL.
	 *
	 * @since 3.0.0
	 * @return bool Whether locale is RTL.
	 */
	public function isRtl()
	{
		return 'rtl' === $this->localeInfo->text_direction;
	}

    /**
     * Resolve the month number.
     * 
     * @param  int|string $month
     * @return int
     */
    public function resolveMonthNumber($month)
    {
        if (is_numeric($month)) {
            $month = (int) $month;
            $month = $this->localeInfo->mappings['month_index_to_num'][$month] ?? null;
        } elseif (is_string($month)) {
            if (strlen($month) === 3) {
                $found = Arr::startsLike(
                    $this->localeInfo->mappings['month'], Str::title($month)
                );
                $month = $found ? key($found) + 1 : null;
            } else {
                $pos = array_search(Str::title($month), static::$monthNames);
                $month = ($pos !== false) ? $pos + 1 : null;
            }
        }

        if (!is_int($month) || $month < 1 || $month > 12) {
            return false;
        }

        return $month;
    }

    /**
     * Resolve the month name.
     * 
     * @param  int|string $month
     * @return string
     */
    public function resolveMonthName($month)
    {
        if (is_numeric($month)) {
            $month = $this->resolveMonthNumber($month);
            $name = $this->localeInfo->mappings['month'][$month - 1];
        } elseif (is_string($month)) {
            $name = Str::title($month);
            if (strlen($month) === 3) {
                $month = Arr::startsLike(
                    $this->localeInfo->mappings['month'], Str::title($month)
                );
                $name = reset($month);
            }
        }
            
        return $name;
    }

    /**
     * Resolve the weekday key.
     * 
     * @param  int|string $day
     * @return int
     */
    public function resolveWeekdayKey($day)
    {
        if (is_numeric($day)) {
            $day = $this->localeInfo->mappings['weekday'][$day];
        } elseif (is_string($day)) {
            $day = Str::title($day);
            if (strlen($day) === 3) {
                $day = Arr::startsLike(
                    $this->localeInfo->mappings['weekday'], Str::title($day)
                );
                $day = reset($day);
            }
        }

        return $day;
    }

    /**
     * Dynamic property accessor.
     * 
     * @param  string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->localeInfo->{$key};
    }

    /**
     * Get the info as array.
     * 
     * @return array
     */
    public function toArray()
    {
    	return (array) $this->localeInfo;
    }

    /**
     * Get the locale identifier.
     * 
     * @return string
     */
    public function toString()
    {
        return $this->locale;
    }

    /**
     * Get the locale identifier.
     * 
     * @return string
     */
    public function __toString()
    {
        return $this->toString();
    }

    /**
     * Alternative constructor.
     * 
     * @param  int|null $userIdOrLocale
     * @return $this
     */
    public static function init($userIdOrLocale = null)
    {
    	return new static($userIdOrLocale);
    }

    /**
     * Load the locale info of a user.
     * 
     * @param  int|null $userIdOrLocale
     * @return \stdClass
     */
    public static function getInfo($userIdOrLocale = null)
    {
        return new Static($userIdOrLocale);
    }
}
