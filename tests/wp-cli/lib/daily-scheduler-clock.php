<?php
/**
 * Test-only clock seam for the DailyScheduler namespace.
 */

namespace FluentCart\App\Hooks\Scheduler\AutoSchedules;

class FcDailySchedulerClock
{
    /** @var int|null */
    private static $timestamp = null;

    /**
     * @param int $timestamp
     * @return void
     */
    public static function freeze($timestamp)
    {
        self::$timestamp = (int) $timestamp;
    }

    /**
     * @return void
     */
    public static function reset()
    {
        self::$timestamp = null;
    }

    /**
     * @return int|null
     */
    public static function frozenTimestamp()
    {
        return self::$timestamp;
    }
}

/**
 * @return int
 */
function time()
{
    $timestamp = FcDailySchedulerClock::frozenTimestamp();

    return $timestamp === null ? \time() : $timestamp;
}

/**
 * @param string   $datetime
 * @param int|null $baseTimestamp
 * @return int|false
 */
function strtotime($datetime, $baseTimestamp = null)
{
    if ($baseTimestamp === null) {
        $baseTimestamp = FcDailySchedulerClock::frozenTimestamp();
    }

    return $baseTimestamp === null
        ? \strtotime($datetime)
        : \strtotime($datetime, $baseTimestamp);
}

/**
 * @param string   $format
 * @param int|null $timestamp
 * @return string
 */
function date($format, $timestamp = null)
{
    if ($timestamp === null) {
        $timestamp = FcDailySchedulerClock::frozenTimestamp();
    }

    return $timestamp === null
        ? \date($format)
        : \date($format, $timestamp);
}
