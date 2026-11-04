<?php

namespace Tests\Support\Traits;

/**
 * An independent oracle for billing-date arithmetic.
 *
 * Renewal tests need to say "one month after payment" without asking the code
 * under test what that means, or the assertion moves whenever the production
 * helper does. PHP's own relative arithmetic is not usable either: strtotime()
 * overflows, turning 31 January into 3 March, so an oracle built on it fails on
 * month-end dates and passes every other day of the year.
 *
 * The rule, stated independently: keep the day of month, clamp it to the last
 * day of the target month when that month is shorter, and carry the time of day
 * unchanged in UTC. This is deliberately built on DateTimeImmutable rather than
 * the gmmktime() arithmetic production uses, so the two agree by the rule and
 * not by construction.
 */
trait CalculatesBillingDates
{
    /** One month after the given instant, month-end clamped, in UTC. */
    protected function oneMonthAfterUtc(int $timestamp): int
    {
        $utc  = new \DateTimeZone('UTC');
        $from = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($utc);

        // Land on the first of the target month before asking how long it is —
        // adding a month to the 31st would overflow before the clamp could run.
        $target = $from->modify('first day of this month')->modify('+1 month');

        $day = min((int) $from->format('j'), (int) $target->format('t'));

        return $target
            ->setDate((int) $target->format('Y'), (int) $target->format('n'), $day)
            ->setTime((int) $from->format('G'), (int) $from->format('i'), (int) $from->format('s'))
            ->getTimestamp();
    }
}
