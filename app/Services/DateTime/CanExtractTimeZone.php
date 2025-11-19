<?php

namespace FluentCart\App\Services\DateTime;

trait CanExtractTimeZone{
    public static function extractTimezone($datetime = null): \DateTimeZone
    {
        $timezone = null;

        if (is_null($datetime)) {
            $datetime = static::now();
            $timezone = $datetime->getTimezone();
        }
        elseif ($datetime instanceof \DateTimeInterface) {
            $timezone = $datetime->getTimezone();
        }
        elseif (is_numeric($datetime)) {
            $dt = new static('@' . (int)$datetime);
            $wpTimezone = wp_timezone();
            $dt->setTimezone($wpTimezone);
            $timezone = $dt->getTimezone();
        }
        elseif (is_string($datetime)) {
            $dt = static::parse($datetime);
            $timezone = $dt->getTimezone();
        }
        else {
            throw new \InvalidArgumentException('Invalid datetime format.');
        }
        return $timezone;
    }




    /**
     * Enhanced offset to named timezone mapping
     */
    private static function getNamedTimezoneFromOffset(string $offset): ?string
    {
        $offsetMap = [
            '+00:00' => 'UTC',
            '+01:00' => 'Europe/Paris',      // Better than London (no DST issues)
            '+02:00' => 'Europe/Berlin',
            '+03:00' => 'Europe/Moscow',
            '+03:30' => 'Asia/Tehran',
            '+04:00' => 'Asia/Dubai',
            '+04:30' => 'Asia/Kabul',
            '+05:00' => 'Asia/Karachi',
            '+05:30' => 'Asia/Kolkata',
            '+06:00' => 'Asia/Dhaka',        // Perfect for Bangladesh!
            '+07:00' => 'Asia/Bangkok',
            '+08:00' => 'Asia/Singapore',
            '+09:00' => 'Asia/Tokyo',
            '+10:00' => 'Australia/Sydney',
            '+11:00' => 'Australia/Melbourne',
            '+12:00' => 'Pacific/Auckland',
            '-05:00' => 'America/New_York',
            '-06:00' => 'America/Chicago',
            '-07:00' => 'America/Denver',
            '-08:00' => 'America/Los_Angeles',
            '-09:00' => 'America/Anchorage',
            '-10:00' => 'Pacific/Honolulu',
        ];

        return $offsetMap[$offset] ?? null;
    }



    public static function getTimezoneOffsetMinutes($timezoneName): int
    {
        try {
            if (preg_match('/^([+-])(\d{2}):(\d{2})$/', $timezoneName, $matches)) {
                // Handle offset format like +06:00
                $sign = $matches[1];
                $hours = (int)$matches[2];
                $minutes = (int)$matches[3];
                $totalMinutes = ($hours * 60) + $minutes;
                return $sign === '-' ? -$totalMinutes : $totalMinutes;
            } else {
                // Handle named timezone like 'Asia/Dhaka'
                $timezone = new \DateTimeZone($timezoneName);
                $utc = new \DateTime('now', new \DateTimeZone('UTC'));
                $local = new \DateTime('now', $timezone);
                return $local->getOffset() / 60; // Convert seconds to minutes
            }
        } catch (\Exception $e) {
            // Fallback to WordPress GMT offset
            $gmt_offset = get_option('gmt_offset', 0);
            return $gmt_offset * 60;
        }
    }
}