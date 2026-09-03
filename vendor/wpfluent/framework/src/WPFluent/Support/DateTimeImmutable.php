<?php

namespace FluentCart\Framework\Support;

use Exception;
use DateTimeZone;
use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use DateTimeImmutable as PHPDateTimeImmutable;

/**
 * @mixin \DateTimeImmutable
 * @method self addYear(int $value = 1)
 * @method self addYears(int $value = 1)
 * @method self addMonth(int $value = 1)
 * @method self addMonths(int $value = 1)
 * @method self addWeek(int $value = 1)
 * @method self addWeeks(int $value = 1)
 * @method self addDay(int $value = 1)
 * @method self addDays(int $value = 1)
 * @method self addHour(int $value = 1)
 * @method self addHours(int $value = 1)
 * @method self addMinute(int $value = 1)
 * @method self addMinutes(int $value = 1)
 * @method self addSecond(int $value = 1)
 * @method self addSeconds(int $value = 1)
 * @method self addQuarter(int $value = 1)
 * @method self addQuarters(int $value = 1)
 * @method self addDecade(int $value = 1)
 * @method self addDecades(int $value = 1)
 *
 * @method self subYear(int $value = 1)
 * @method self subYears(int $value = 1)
 * @method self subMonth(int $value = 1)
 * @method self subMonths(int $value = 1)
 * @method self subWeek(int $value = 1)
 * @method self subWeeks(int $value = 1)
 * @method self subDay(int $value = 1)
 * @method self subDays(int $value = 1)
 * @method self subHour(int $value = 1)
 * @method self subHours(int $value = 1)
 * @method self subMinute(int $value = 1)
 * @method self subMinutes(int $value = 1)
 * @method self subSecond(int $value = 1)
 * @method self subSeconds(int $value = 1)
 * @method self subQuarter(int $value = 1)
 * @method self subQuarters(int $value = 1)
 * @method self subDecade(int $value = 1)
 * @method self subDecades(int $value = 1)
 *
 * @method int getYear()
 * @method int getMonth()
 * @method int getDay()
 * @method int getHour()
 * @method int getMinute()
 * @method int getSecond()
 * @method int getQuarter()
 * @method int getDecade()
 *
 * @method self setYear(int $value)
 * @method self setMonth(int $value)
 * @method self setDay(int $value)
 * @method self setHour(int $value)
 * @method self setMinute(int $value)
 * @method self setSecond(int $value)
 */
class DateTimeImmutable extends PHPDateTimeImmutable
{
    /** @var string[] Singular time units */
    protected const SINGULAR_UNITS = [
        'year', 'month', 'week', 'day', 'hour',
        'minute', 'second', 'quarter', 'decade'
    ];

    /** @var string[] Plural time units */
    protected const PLURAL_UNITS = [
        'years', 'months', 'weeks', 'days', 'hours',
        'minutes', 'seconds', 'quarters', 'decades'
    ];

    /**
     * Construct the object.
     *
     * @param string|int|DateTimeInterface $datetime
     * @param DateTimeZone|string|null $timezone
     */
    public function __construct($datetime = 'now', $timezone = null)
    {
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }

        $timezone ??= static::getDefaultTimezone();

        if ($datetime instanceof DateTimeInterface) {
            $datetime = $datetime->format('Y-m-d H:i:s.u');
        } elseif (
        	is_numeric($datetime)
        	|| str_starts_with((string) $datetime, '@')
        ) {
            $datetime = '@' . ltrim((string) $datetime, '@');
        }

        parent::__construct($datetime, $timezone);
    }

    /**
     * Gets the WordPress default timezone.
     *
     * @return DateTimeZone
     */
    public static function getDefaultTimezone()
    {
        $tz = wp_timezone();

        return $tz instanceof DateTimeZone ? $tz : new DateTimeZone($tz);
    }

    /**
     * Creates a DateTime object from various inputs.
     *
     * @param string|int|DateTimeInterface $time
     * @param string|DateTimeZone|null $tz
     *
     * @return static
     * @throws \InvalidArgumentException
     */
    public static function create($time = null, $tz = null)
	{
	    if (is_null($tz)) {
	        $timezone = (new static)->getDefaultTimezone();
	    } else {
	        $timezone = is_string($tz) ? new DateTimeZone($tz) : $tz;
	        if (!$timezone instanceof DateTimeZone) {
	            throw new InvalidArgumentException('Invalid timezone.');
	        }
	    }

	    // Handle DateTimeInterface directly
	    if ($time instanceof DateTimeInterface) {
	        $dateTime = new static(
	        	$time->format('Y-m-d H:i:s.u'), $time->getTimezone()
	        );

	        // Override timezone if explicitly provided
	        if ($tz !== null) {
	            $dateTime = $dateTime->setTimezone($timezone);
	        }

	        return $dateTime;
	    }

	    // Handle numeric timestamps
	    if (is_numeric($time)) {
	        // Treat small numbers as offsets from now (optional)
	        if ($time <= 31556952) { // ~seconds in a year
	            $time += time();
	        }

	        // create from timestamp
	        $dateTime = new static('@' . $time);
	        return $dateTime->setTimezone($timezone);
	    }

	    // Fallback: treat as string or null ('now')
	    $dateTime = new static($time ?: 'now', $timezone);

	    return $dateTime;
	}

    /**
     * Creates a new instance for the current time.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function now($tz = null)
    {
        return static::create('now', $tz);
    }

    /**
     * Creates a new instance for the beginning of today.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function today($tz = null)
    {
        return static::create('today', $tz)->startOfDay();
    }

    /**
     * Creates a new instance for the beginning of yesterday.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function yesterday($tz = null)
    {
        return static::now($tz)->modify('-1 day')->startOfDay();
    }

    /**
     * Creates a new instance for the beginning of tomorrow.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function tomorrow($tz = null)
    {
        return static::now($tz)->modify('+1 day')->startOfDay();
    }

    /**
     * Creates a new instance for the beginning of the current week.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function currentWeek($tz = null)
    {
        return static::now($tz)->startOfWeek();
    }

    /**
     * Creates a new instance for the beginning of last week.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function lastWeek($tz = null)
    {
        return static::now($tz)->subWeek()->startOfWeek();
    }

    /**
     * Creates a new instance for the beginning of next week.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function nextWeek($tz = null)
    {
        return static::now($tz)->addWeek()->startOfWeek();
    }

    /**
     * Creates a new instance for the beginning of the current month.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function currentMonth($tz = null)
    {
        return static::now($tz)->startOfMonth();
    }

    /**
     * Creates a new instance for the beginning of last month.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function lastMonth($tz = null)
    {
        return static::now($tz)->subMonth()->startOfMonth();
    }

    /**
     * Creates a new instance for the beginning of next month.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function nextMonth($tz = null)
    {
        return static::now($tz)->addMonth()->startOfMonth();
    }

    /**
     * Creates a new instance for the beginning of the current year.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function currentYear($tz = null)
    {
        return static::now($tz)->startOfYear();
    }

    /**
     * Creates a new instance for the beginning of last year.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function lastYear($tz = null)
    {
        return static::now($tz)->subYear()->startOfYear();
    }

    /**
     * Creates a new instance for the beginning of next year.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function nextYear($tz = null)
    {
        return static::now($tz)->addYear()->startOfYear();
    }

    /**
     * Sets the timezone for the instance.
     *
     * @param string|DateTimeZone $tz
     * @return self
     */
    public function timezone($tz)
    {
        if (is_string($tz)) {
            $tz = new DateTimeZone($tz);
        }
        return $this->setTimezone($tz);
    }

    /**
     * Checks if the current instance is between two dates.
     *
     * @param string|DateTimeInterface $date1
     * @param string|DateTimeInterface $date2
     * @return bool
     */
    public function between($date1, $date2)
    {
        $date1 = $date1 instanceof DateTimeInterface ? $date1 : new static($date1);
        $date2 = $date2 instanceof DateTimeInterface ? $date2 : new static($date2);

        if ($date1 > $date2) {
            [$date1, $date2] = [$date2, $date1];
        }

        return ($this >= $date1 && $this <= $date2);
    }

    /**
     * Creates a new instance from a formatted string.
     *
     * @param string $format
     * @param string $datetimeString
     * @param string|DateTimeZone|null $timezone
     *
     * @return static
     * @throws \InvalidArgumentException
     */
    public static function createFromFormat(
    	$format,
    	$datetimeString,
    	$timezone = null
    )
    {
        if (is_null($timezone)) {
            $timezone = (new static)->getDefaultTimezone();
        } else {
            $timezone = is_string($timezone)
            	? new DateTimeZone($timezone)
            	: $timezone;
        }

        if (!$timezone instanceof DateTimeZone) {
            throw new InvalidArgumentException('Invalid timezone.');
        }

        $dateTime = PHPDateTimeImmutable::createFromFormat(
        	$format, $datetimeString
        );
        
        if ($dateTime !== false) {
            
            if (!$dateTime instanceof static) {
                return new static(
                    $dateTime->format(ltrim($format, '!')), $timezone
                );
            }

            $dateTime->setTimezone($timezone);

            return $dateTime;
        }

        throw new InvalidArgumentException(
            "Unable to create datetime from: {$datetimeString}."
        );
    }

    /**
     * Creates a new instance from date parts.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param int $second
     * @param string|DateTimeZone|null $tz
     *
     * @return static
     * @throws \InvalidArgumentException
     */
    public static function createFromDate(
        int $year,
        int $month,
        int $day,
        int $hour = 0,
        int $minute = 0,
        int $second = 0,
        $tz = null
    ) {
        if (
            !checkdate($month, $day, $year)
            || $hour < 0 || $hour > 23
            || $minute < 0 || $minute > 59
            || $second < 0 || $second > 59
        ) {
            throw new InvalidArgumentException(
                sprintf("Invalid date '%04d-%02d-%02d %02d:%02d:%02d'", $year, $month, $day, $hour, $minute, $second)
            );
        }

        return new static(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second), $tz);
    }

    /**
     * Creates a new instance from a UTC date string.
     *
     * @param string $dateString
     * @param string $format
     * @return static
     */
    public static function createFromUTC(
    	string $dateString,
    	string $format = 'Y-m-d H:i:s'
    )
    {
        $gmtDate = get_date_from_gmt($dateString, $format);
        return new static($gmtDate, static::getDefaultTimezone());
    }

    /**
     * Parses a datetime string and returns a new instance.
     *
     * @param string $datetimeString
     * @param string|DateTimeZone|null $timezone
     *
     * @return static
     * @throws \InvalidArgumentException
     */
    public static function parse($datetimeString, $timezone = null)
    {
        try {
            return new static($datetimeString, $timezone);
        } catch (Exception $e) {
            throw new InvalidArgumentException(
            	'Unable to handle datetime.', 0, $e
            );
        }
    }

    /**
     * Adds an interval to the instance.
     *
     * @param DateInterval|string $interval
     * @return self
     * @throws \InvalidArgumentException
     */
    public function add($interval)
    {
        if ($interval instanceof DateInterval) {
            return parent::add($interval);
        }

        if (!is_string($interval) || trim($interval) === '') {
            throw new InvalidArgumentException('Invalid interval string.');
        }

        return $this->modify('+' . $interval);
    }

    /**
     * Subtracts an interval from the instance.
     *
     * @param DateInterval|string $interval
     * @return self
     * @throws \InvalidArgumentException
     */
    public function sub($interval)
    {
        if ($interval instanceof DateInterval) {
            return parent::sub($interval);
        }

        if (!is_string($interval) || trim($interval) === '') {
            throw new InvalidArgumentException('Invalid interval string.');
        }

        return $this->modify('-' . $interval);
    }

    /**
     * Creates a copy of the instance.
     *
     * @return self
     */
    public function copy()
    {
        return clone $this;
    }

    /**
     * Converts the instance to a string.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->format('Y-m-d H:i:s');
    }

    /**
     * Checks if the instance has a timezone.
     *
     * @return bool
     */
    public function hasTimezone()
    {
        return $this->getTimezone() instanceof DateTimeZone;
    }

    /**
     * Sets the time to the beginning of the day.
     *
     * @return self
     */
    public function startOfDay()
    {
    	return $this->setTime(0, 0, 0, 0);
    }

    /**
     * Sets the time to the end of the day.
     *
     * @return self
     */
    public function endOfDay()
    {
    	return $this->setTime(23, 59, 59, 999999);
    }

    /**
     * Sets the time to the beginning of the hour.
     *
     * @return self
     */
    public function startOfHour()
    {
    	return $this->setTime((int)$this->format('H'), 0, 0, 0);
    }

    /**
     * Sets the time to the end of the hour.
     *
     * @return self
     */
    public function endOfHour()
    {
    	return $this->setTime((int)$this->format('H'), 59, 59, 999999);
    }

    /**
     * Sets the time to the beginning of the minute.
     *
     * @return self
     */
    public function startOfMinute()
    {
    	return $this->setTime(
    		(int)$this->format('H'), (int)$this->format('i'), 0, 0
    	);
    }

    /**
     * Sets the time to the end of the minute.
     *
     * @return self
     */
    public function endOfMinute()
    {
    	return $this->setTime(
    		(int)$this->format('H'),
    		(int)$this->format('i'), 59, 999999
    	);
    }

    /**
     * Sets the date to the beginning of the week.
     *
     * @return self
     */
    public function startOfWeek()
    {
        $start = max(0, min(6, (int)get_option('start_of_week')));
        
        $day = (int)$this->format('w');
        
        $diff = $day - $start;
        
        if ($diff < 0) $diff += 7;

        return $this->sub(new DateInterval("P{$diff}D"))->startOfDay();
    }

    /**
     * Sets the date to the end of the week.
     *
     * @return self
     */
    public function endOfWeek()
    {
        $start = max(0, min(6, (int)get_option('start_of_week')));

        $day = (int)$this->format('w');

        $end = ($start + 6) % 7;

        $diff = $end - $day;

        if ($diff < 0) $diff += 7;

        return $this->add(new DateInterval("P{$diff}D"))->endOfDay();
    }

    /**
     * Sets the date to the beginning of the month.
     *
     * @return self
     */
    public function startOfMonth()
    {
        return $this->setDate(
        	(int)$this->format('Y'), (int)$this->format('m'), 1
        )->startOfDay();
    }

    /**
     * Sets the date to the end of the month.
     *
     * @return self
     */
    public function endOfMonth()
    {
        return $this->modify('last day of this month')->endOfDay();
    }

    /**
     * Sets the date to the beginning of the quarter.
     *
     * @return self
     */
    public function startOfQuarter()
    {
        $month = (int)$this->format('m');

        $startMonth = $month <= 3 ? 1 : (
        	$month <= 6 ? 4 : ($month <= 9 ? 7 : 10)
        );
        
        return $this->setDate(
        	(int)$this->format('Y'), $startMonth, 1
        )->startOfDay();
    }

    /**
     * Sets the date to the end of the quarter.
     *
     * @return self
     */
    public function endOfQuarter()
    {
        $month = (int)$this->format('m');

        $endMonth = $month <= 3 ? 3 : ($month <= 6 ? 6 : ($month <= 9 ? 9 : 12));

        $lastDay = (int)date('t', strtotime(
        	"{$this->format('Y')}-{$endMonth}-01"
        ));

        return $this->setDate(
        	(int)$this->format('Y'), $endMonth, $lastDay
        )->endOfDay();
    }

    /**
     * Sets the date to the beginning of the year.
     *
     * @return self
     */
    public function startOfYear()
    {
    	return $this->setDate(
    		(int)$this->format('Y'), 1, 1
    	)->startOfDay();
    }

    /**
     * Sets the date to the end of the year.
     *
     * @return self
     */
    public function endOfYear()
    {
    	return $this->setDate(
    		(int)$this->format('Y'), 12, 31
    	)->endOfDay();
    }

    /**
     * Sets the date to the beginning of the decade.
     *
     * @return self
     */
    public function startOfDecade()
    {
        $year = (int)$this->format('Y');
        $start = $year - ($year % 10);
        return $this->setDate($start, 1, 1)->startOfDay();
    }

    /**
     * Sets the date to the end of the decade.
     *
     * @return self
     */
    public function endOfDecade()
    {
        $year = (int)$this->format('Y');
        $end = $year + (9 - ($year % 10));
        return $this->setDate($end, 12, 31)->endOfDay();
    }

    /**
     * Magic setter for time units.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     * @throws \InvalidArgumentException
     */
    public function __set($key, $value)
    {
        $new = clone $this;
        switch ($key) {
            case 'year': return $new->setDate(
            	(int)$value,
            	(int)$this->format('m'),
            	(int)$this->format('d')
            );

            case 'month': return $new->setDate(
            	(int)$this->format('Y'),
            	(int)$value,
            	(int)$this->format('d')
            );

            case 'day': return $new->setDate(
            	(int)$this->format('Y'),
            	(int)$this->format('m'),
            	(int)$value
            );

            case 'hour': return $new->setTime(
            	(int)$value,
            	(int)$this->format('i'),
            	(int)$this->format('s')
            );

            case 'minute': return $new->setTime(
            	(int)$this->format('H'),
            	(int)$value,
            	(int)$this->format('s')
            );

            case 'second': return $new->setTime(
            	(int)$this->format('H'),
            	(int)$this->format('i'),
            	(int)$value
            );

            default: throw new InvalidArgumentException(
            	"Cannot set unknown property '{$key}'."
            );
        }
    }

    /**
     * Magic method for dynamic calls (e.g., `addDay`, `getYear`).
     *
     * @param string $method
     * @param array $params
     * @return mixed|self
     * @throws \InvalidArgumentException
     */
    public function __call($method, $params)
    {
        // set* methods
        if (strpos($method, 'set') === 0) {
            $unit = lcfirst(substr($method, 3));
            if ($params && in_array($unit, static::SINGULAR_UNITS, true)) {
                return $this->__set($unit, reset($params));
            }
        }

        // get* methods
        if (strpos($method, 'get') === 0) {
            $unit = lcfirst(substr($method, 3));
            switch ($unit) {
                case 'year': return (int)$this->format('Y');
                case 'month': return (int)$this->format('m');
                case 'day': return (int)$this->format('d');
                case 'hour': return (int)$this->format('H');
                case 'minute': return (int)$this->format('i');
                case 'second': return (int)$this->format('s');
                case 'quarter': return (int)(ceil((int)$this->format('m') / 3));
                case 'decade': return (int)floor((int)$this->format('Y') / 10) * 10;
                default: throw new InvalidArgumentException(
                	"Call to undefined method {$method}."
                );
            }
        }

        // add*/sub* methods
        $action = null;
        if (strpos($method, 'add') === 0) $action = '+';
        elseif (strpos($method, 'sub') === 0) $action = '-';

        if ($action) {
            $duration = $params[0] ?? 1;
            $unit = lcfirst(substr($method, 3));

            if (
            	!in_array(
            		$unit,
            		array_merge(
            			static::SINGULAR_UNITS,
            			static::PLURAL_UNITS
            		), true
            	)
            ) {
                throw new InvalidArgumentException(
                	"Call to undefined method {$method}."
                );
            }

            // Special handling for quarter (3 months)
            if ($unit === 'quarter' || $unit === 'quarters') $unit = 'month';
            // Special handling for decade (10 years)
            if ($unit === 'decade' || $unit === 'decades') $unit = 'year';

            $multiplier = 1;
            if (stripos($method, 'quarter') !== false) $multiplier = 3;
            if (stripos($method, 'decade') !== false) $multiplier = 10;

            return $this->modify(
            	"{$action}" . ($duration * $multiplier) . " {$unit}"
            );
        }

        throw new InvalidArgumentException("Call to undefined method {$method}.");
    }
}
