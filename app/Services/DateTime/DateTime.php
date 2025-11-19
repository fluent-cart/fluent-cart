<?php

namespace FluentCart\App\Services\DateTime;

use DateTimeInterface;
use DateTimeZone;

class DateTime extends \FluentCart\Framework\Support\DateTime
{

    use CanExtractTimeZone;

    /**
     * Create a new DateTime Object with current GMT/UTC time
     *
     * @return static
     */
    public static function gmtNow()
    {

        return static::create('now', new DateTimeZone('UTC'));
    }


    public static function now($tz = null)
    {
        return static::create('now', new DateTimeZone('UTC'));
    }

    /**
     * Convert any datetime to GMT/UTC
     *
     * @param string|DateTimeInterface|null $datetime The datetime to convert (defaults to current instance)
     * @return static
     */
    public static function anyTimeToGmt($datetime = null, $fromTz = null)
    {
        if (is_null($datetime)) {
            $datetime = static::now();
            return (clone $datetime)->setTimezone(new DateTimeZone('UTC'));
        }

        // If it's already a Carbon or DateTime instance
        if ($datetime instanceof \DateTimeInterface) {
            $dt = clone $datetime;

            // If fromTz is specified and the datetime doesn't have timezone info, set it
            if ($fromTz && $dt->getTimezone()->getName() === 'UTC' && $fromTz !== 'UTC') {
                $dt = $dt->setTimezone(new \DateTimeZone($fromTz));
            }

            return $dt->setTimezone(new \DateTimeZone('UTC'));
        }

        // If it's a Unix timestamp (int or numeric string)
        if (is_numeric($datetime)) {
            $dt = new static('@' . (int)$datetime);
            return $dt->setTimezone(new \DateTimeZone('UTC'));
        }

        // If it's a string, parse it with optional timezone context
        if (is_string($datetime)) {
            $dt = static::parse($datetime);

            // If fromTz is provided and the parsed string doesn't contain timezone info
            if ($fromTz && !preg_match('/[+-]\d{2}:?\d{2}$|Z$|[A-Z]{3,4}$/i', trim($datetime))) {
                // Create a new datetime in the specified timezone
                $dt = static::createFromFormat('Y-m-d H:i:s', $dt->format('Y-m-d H:i:s'), new \DateTimeZone($fromTz));
            }

            return $dt->setTimezone(new \DateTimeZone('UTC'));
        }

        throw new \InvalidArgumentException(esc_html__('Invalid datetime format.', 'fluent-cart'));
    }

    public static function gmdate($datetime = null, $fromTz = null)
    {
        return static::anyTimeToGmt($datetime, $fromTz);
    }


    /**
     * Convert a GMT/UTC datetime to the given timezone
     *
     * @param string|\DateTimeInterface|null $datetime The datetime to convert (defaults to now in UTC)
     * @param string|null $timezone Target timezone (defaults to site timezone)
     * @return static
     */
    public static function gmtToTimezone($datetime = null, $timezone = null): DateTime
    {
        // If not a string or DateTimeZone, fallback to wp_timezone()
        if (!$timezone instanceof \DateTimeZone) {
            $timezone = is_string($timezone)
                ? new \DateTimeZone($timezone)
                : wp_timezone(); // returns a DateTimeZone object
        }


        if (is_null($datetime)) {
            $datetime = static::now(); // returns UTC time
        }

        if ($datetime instanceof \DateTimeInterface) {
            return (clone $datetime)->setTimezone($timezone);
        }

        if (is_numeric($datetime)) {
            $dt = new static('@' . (int)$datetime);
            return $dt->setTimezone($timezone);
        }

        if (is_string($datetime)) {
            $dt = static::parse($datetime, new \DateTimeZone('UTC')); // assume UTC
            return $dt->setTimezone($timezone);
        }
        throw new \InvalidArgumentException(esc_html__('Invalid datetime format.', 'fluent-cart'));

    }


    public function getDefaultTimezone()
    {
        return new DateTimeZone('UTC');
    }


    public static function parse($datetimeString, $timezone = null)
    {
        $hasTimezone = preg_match('/([Zz]|[+-]\d{2}:\d{2}|[+-]\d{4})/', $datetimeString);

        try {
            if ($hasTimezone) {
                // Use the timezone embedded in the string
                $dt = new \DateTimeImmutable($datetimeString);
            } else {
                $tz = $timezone instanceof \DateTimeZone
                    ? $timezone
                    : ($timezone ? new \DateTimeZone($timezone) : new DateTimeZone('UTC'));

                $dt = new \DateTimeImmutable($datetimeString, $tz);
            }

            return new static($dt->format('Y-m-d H:i:s'), $dt->getTimezone());
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                esc_html__('Unable to parse datetime: ', 'fluent-cart') . esc_html($e->getMessage())
            );
        }
    }

}
