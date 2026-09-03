<?php

namespace FluentCart\App\Services\Email;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Report\DefaultReportService;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;

/**
 * Store Digest email — daily / weekly / monthly.
 *
 * A system email summarising store activity for a finished period. It does NOT
 * flow through the order-bound notification mailer; it builds its own body and
 * renders it through the shared general_template wrapper.
 *
 * Numbers mirror the admin Reports dashboard exactly: same aggregate method
 * (DefaultReportService::getAllGraphMetricsSeparate) and the same paid-status
 * filter (Status::getReportStatuses()).
 */
class StoreDigestService
{
    const CADENCES = ['daily', 'weekly', 'monthly'];

    const OPTION_KEY = 'fluent_cart_store_digest_settings';

    // Single (non-autoloaded) option holding the per-cadence "last sent" stamps.
    const LAST_SENT_OPTION = 'fluent_cart_digest_last_sent';

    /**
     * Per-request settings cache (busted on save).
     *
     * @var array|null
     */
    private static $settingsCache = null;

    /**
     * Default settings for this module. The store digest owns its own settings
     * store — it does NOT live inside the EmailNotifications config.
     */
    public static function defaultSettings(): array
    {
        return [
            // DERIVED on save (yes if any cadence enabled); cheap scheduler early-out.
            // Defaults to 'yes' because the weekly digest is on by default.
            'enabled'         => 'yes',
            'recipients'      => '{{wp.admin_email}}', // comma-separated; shared by all cadences
            'send_when_empty' => 'no',                 // skip periods with zero activity (no "$0" emails to inactive stores)
            'daily'           => ['enabled' => 'no', 'send_hour' => 8],
            'weekly'          => ['enabled' => 'yes', 'send_hour' => 8, 'send_dow' => 1], // ON by default — Monday 08:00
            'monthly'         => ['enabled' => 'no', 'send_hour' => 8], // always sent on the 1st
        ];
    }

    /**
     * Read the module's settings, merged over defaults (cadence sub-arrays
     * deep-merged). Cached per request.
     *
     * @param string|null $key dot-path (e.g. 'recipients', 'daily.send_hour')
     * @return mixed
     */
    public static function getSettings($key = null)
    {
        if (self::$settingsCache === null) {
            $defaults = self::defaultSettings();
            $stored = get_option(self::OPTION_KEY, []);
            if (!is_array($stored)) {
                $stored = [];
            }

            $merged = wp_parse_args($stored, $defaults);
            foreach (self::CADENCES as $cadence) {
                $cadenceStored = (isset($stored[$cadence]) && is_array($stored[$cadence])) ? $stored[$cadence] : [];
                $merged[$cadence] = wp_parse_args($cadenceStored, $defaults[$cadence]);
            }

            self::$settingsCache = $merged;
        }

        if (!empty($key)) {
            return Arr::get(self::$settingsCache, $key);
        }

        return self::$settingsCache;
    }

    /**
     * Sanitize raw input, derive the `enabled` master flag, and persist.
     *
     * The module owns its own input handling: callers pass the raw request data
     * and the service guarantees the stored shape is always clean. `enabled` is
     * derived server-side (ON when any cadence is enabled) so the hourly scheduler
     * can short-circuit on a single cheap check.
     *
     * @param array $input raw, unsanitized settings (e.g. $request->all())
     * @return array the stored settings
     */
    public static function saveSettings(array $input): array
    {
        $clean = self::sanitize($input);

        $clean['enabled'] = (
            $clean['daily']['enabled'] === 'yes'
            || $clean['weekly']['enabled'] === 'yes'
            || $clean['monthly']['enabled'] === 'yes'
        ) ? 'yes' : 'no';

        update_option(self::OPTION_KEY, $clean, false);
        self::$settingsCache = null; // bust per-request cache

        return $clean;
    }

    /**
     * Coerce raw input into the settings shape. `enabled` is intentionally not
     * read from input — it is derived in saveSettings().
     */
    private static function sanitize(array $input): array
    {
        $defaults = self::defaultSettings();

        return [
            'recipients'      => sanitize_text_field(Arr::get($input, 'recipients', $defaults['recipients'])),
            'send_when_empty' => Arr::get($input, 'send_when_empty') === 'yes' ? 'yes' : 'no',
            'daily'           => [
                'enabled'   => Arr::get($input, 'daily.enabled') === 'yes' ? 'yes' : 'no',
                'send_hour' => self::clampInt(Arr::get($input, 'daily.send_hour', $defaults['daily']['send_hour']), 0, 23),
            ],
            'weekly'          => [
                'enabled'   => Arr::get($input, 'weekly.enabled') === 'yes' ? 'yes' : 'no',
                'send_hour' => self::clampInt(Arr::get($input, 'weekly.send_hour', $defaults['weekly']['send_hour']), 0, 23),
                'send_dow'  => self::clampInt(Arr::get($input, 'weekly.send_dow', $defaults['weekly']['send_dow']), 1, 7),
            ],
            'monthly'         => [
                'enabled'   => Arr::get($input, 'monthly.enabled') === 'yes' ? 'yes' : 'no',
                'send_hour' => self::clampInt(Arr::get($input, 'monthly.send_hour', $defaults['monthly']['send_hour']), 0, 23),
            ],
        ];
    }

    private static function clampInt($value, int $min, int $max): int
    {
        $value = (int) $value;
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    /**
     * Hooked to fluent_cart/scheduler/hourly_tasks. Evaluates every cadence
     * against the current site-local time and dispatches the ones that are due.
     */
    public static function runDueDigests(): void
    {
        $config = self::getSettings();

        // Global master switch (derived on save): one cheap check short-circuits all per-cadence work.
        if (Arr::get($config, 'enabled') !== 'yes') {
            return;
        }

        $hour = (int) current_time('G'); // site-local hour, 0-23

        foreach (self::CADENCES as $frequency) {
            self::maybeSend($frequency, Arr::get($config, $frequency, []), $hour);
        }
    }

    /**
     * Decide whether a single cadence is due, and send once if so.
     */
    private static function maybeSend(string $frequency, $cadenceConfig, int $hour): void
    {
        if (Arr::get($cadenceConfig, 'enabled') !== 'yes') {
            return;
        }

        // Must be the scheduled day for weekly/monthly.
        if ($frequency === 'weekly') {
            // N = ISO-8601 day of week (1=Mon ... 7=Sun)
            if ((int) current_time('N') !== (int) Arr::get($cadenceConfig, 'send_dow', 1)) {
                return;
            }
        } elseif ($frequency === 'monthly') {
            // Monthly digest sends on the 1st (reporting the previous calendar month).
            if ((int) current_time('j') !== 1) {
                return;
            }
        }

        // Send at OR AFTER the configured hour, not only exactly on it. WP-Cron /
        // Action Scheduler do not guarantee an hourly tick lands inside every clock
        // hour (low traffic, backlog, drift), so an 08:00 digest must still go out if
        // the first tick of the day is at 09:xx. A later tick the same scheduled day
        // catches up; the per-period stamp below still guarantees exactly one send.
        if ($hour < (int) Arr::get($cadenceConfig, 'send_hour', 8)) {
            return;
        }

        $stamp = self::dedupStamp($frequency);

        $lastSent = get_option(self::LAST_SENT_OPTION, []);
        if (!is_array($lastSent)) {
            $lastSent = [];
        }

        if (Arr::get($lastSent, $frequency) === $stamp) {
            return; // already sent for this period
        }

        // Mark BEFORE sending so a duplicate cron run cannot double-send.
        $lastSent[$frequency] = $stamp;
        update_option(self::LAST_SENT_OPTION, $lastSent, false);

        self::sendDigest($frequency);
    }

    /**
     * Per-cadence idempotency key based on the site-local calendar.
     */
    private static function dedupStamp(string $frequency): string
    {
        if ($frequency === 'weekly') {
            return 'W-' . current_time('o-W'); // ISO year-week
        }
        if ($frequency === 'monthly') {
            return 'M-' . current_time('Y-m');
        }
        return 'D-' . current_time('Y-m-d');
    }

    /**
     * Build and send one digest.
     *
     * @param string $frequency          daily|weekly|monthly
     * @param string $recipientsOverride optional comma-separated recipients (test send)
     * @return bool true when an email was dispatched
     */
    public static function sendDigest(string $frequency, string $recipientsOverride = ''): bool
    {
        if (!in_array($frequency, self::CADENCES, true)) {
            return false;
        }

        $config = self::getSettings();
        $isTest = $recipientsOverride !== '';

        $recipients = self::resolveRecipients(
            $isTest ? $recipientsOverride : Arr::get($config, 'recipients', ''),
            $frequency,
            $config
        );

        if (empty($recipients)) {
            fluent_cart_add_log(
                'Store Digest skipped',
                __('No valid recipient is configured for the store digest email.', 'fluent-cart'),
                'error'
            );
            return false;
        }

        $payload = self::buildPayload($frequency);

        // Skip empty periods unless opted in. Test sends always go through.
        if (!$isTest && Arr::get($payload, 'is_empty') && Arr::get($config, 'send_when_empty', 'no') !== 'yes') {
            return false;
        }

        $body = self::renderEmailBody($payload);

        $mailer = Mailer::make()
            ->to(implode(',', $recipients))
            ->subject(Arr::get($payload, 'subject', ''))
            ->body($body);

        return (bool) $mailer->send(true);
    }

    /**
     * Assemble the digest payload: current + prior window totals, deltas, top products.
     */
    public static function buildPayload(string $frequency): array
    {
        list($startDate, $endDate) = self::windowFor($frequency);
        list($prevStart, $prevEnd) = self::priorWindowFor($frequency);

        $current = self::metricsFor($startDate, $endDate);
        $previous = self::metricsFor($prevStart, $prevEnd);

        $fluctuations = DefaultReportService::make([])->calculateFluctuations($current, $previous);

        $orderCount = (int) Arr::get($current, 'order_count', 0);

        $payload = [
            'frequency'       => $frequency,
            'frequency_label' => self::frequencyLabel($frequency),
            'period_label'    => self::periodLabel($frequency, $startDate, $endDate),
            'store_name'      => self::storeName(),
            'intro'           => '',
            'reports_url'     => admin_url('admin.php?page=fluent-cart#/reports'),
            'settings_url'    => admin_url('admin.php?page=fluent-cart#/settings/email_digest_settings'),
            'is_pro'          => App::isProActive(),
            'pro_url'         => apply_filters('fluent_cart/store_digest/pro_url', 'https://fluentcart.com/discount-deal/'),
            'is_empty'        => $orderCount === 0,
            'subject'         => '',
            'metrics'         => [
                'gross_sale'         => self::money(Arr::get($current, 'gross_sale', 0)),
                'net_revenue'        => self::money(Arr::get($current, 'net_revenue', 0)),
                'refund_amount'      => self::money(Arr::get($current, 'total_refunded_amount', 0)),
                'refund_count'       => (int) Arr::get($current, 'total_refunded', 0),
                'order_count'        => $orderCount,
                'items_sold'         => (int) Arr::get($current, 'total_item_count', 0),
                'onetime_count'      => (int) Arr::get($current, 'onetime_count', 0),
                'onetime_gross'      => self::money(Arr::get($current, 'onetime_gross', 0)),
                'subscription_count' => (int) Arr::get($current, 'subscription_count', 0),
                'subscription_gross' => self::money(Arr::get($current, 'subscription_gross', 0)),
                'renewal_count'      => (int) Arr::get($current, 'renewal_count', 0),
                'renewal_gross'      => self::money(Arr::get($current, 'renewal_gross', 0)),
            ],
            'fluctuations'    => [
                'gross_sale'    => self::fluctuation($fluctuations, 'gross_sale'),
                'net_revenue'   => self::fluctuation($fluctuations, 'net_revenue'),
                'order_count'   => self::fluctuation($fluctuations, 'order_count'),
                'items_sold'    => self::fluctuation($fluctuations, 'total_item_count'),
                'refund_amount' => self::fluctuation($fluctuations, 'total_refunded_amount'),
            ],
            'top_products'    => self::topProducts($startDate, $endDate),
        ];

        $payload['subject'] = self::subjectFor($payload);
        $payload['intro'] = self::introLine($frequency, (string) Arr::get($payload, 'store_name', ''));

        // Pro upsell card: a contextual angle chosen from THIS period's own numbers,
        // falling back to a deterministic per-store rotation when no signal stands
        // out. Only built for free installs — Pro hides the card entirely. Built
        // before the data filter so integrations can override the chosen copy.
        $payload['pro_promo'] = Arr::get($payload, 'is_pro') ? [] : self::proPromo($payload);

        $payload = apply_filters('fluent_cart/store_digest/data', $payload, [
            'frequency' => $frequency,
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ]);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Window totals for a date range — same query + paid-status filter the
     * Reports dashboard uses, so numbers always agree.
     */
    private static function metricsFor(string $startDate, string $endDate): array
    {
        $result = DefaultReportService::make([])->getAllGraphMetricsSeparate([
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'groupKey'      => 'daily', // concrete key: totals are summed regardless of grouping, and avoids defineGroupKey() which needs DateTime objects
            'currency'      => null,
            'variationIds'  => [],
            'paymentStatus' => Status::getReportStatuses(),
        ]);

        return (array) Arr::get($result, 'summary', []);
    }

    /**
     * Top 5 products by units sold, each carrying units and revenue.
     */
    private static function topProducts(string $startDate, string $endDate): array
    {
        $result = DefaultReportService::make([])->fetchTopSoldProducts([
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'groupKey'      => 'daily', // concrete key: totals are summed regardless of grouping, and avoids defineGroupKey() which needs DateTime objects
            'currency'      => null,
            'variationIds'  => [],
            'paymentStatus' => Status::getReportStatuses(),
        ]);

        $top = [];
        foreach (Arr::get($result, 'topSoldProducts', []) as $product) {
            $top[] = [
                'name'    => Arr::get($product, 'product_name', __('Unknown Product', 'fluent-cart')),
                'qty'     => (int) Arr::get($product, 'quantity_sold', 0),
                'revenue' => self::money(Arr::get($product, 'total_amount', 0)),
            ];
            if (count($top) >= 5) {
                break;
            }
        }

        return $top;
    }

    /**
     * Current finished-period window as site-local wall-clock strings.
     *
     * created_at is stored in UTC, but — to match the dashboard exactly — we
     * query with bare local strings (no GMT conversion), built from
     * current_time() via the same gmdate() idiom the dashboard uses.
     *
     * @return array [startDate, endDate]
     */
    private static function windowFor(string $frequency): array
    {
        $localNow = current_time('timestamp'); // site-local Unix timestamp

        if ($frequency === 'weekly') {
            return [
                gmdate('Y-m-d 00:00:00', strtotime('-7 days', $localNow)),
                gmdate('Y-m-d 23:59:59', strtotime('-1 day', $localNow)),
            ];
        }

        if ($frequency === 'monthly') {
            $firstOfLastMonth = strtotime('first day of last month', $localNow);
            return [
                gmdate('Y-m-01 00:00:00', $firstOfLastMonth),
                gmdate('Y-m-t 23:59:59', $firstOfLastMonth),
            ];
        }

        // daily — yesterday
        $day = gmdate('Y-m-d', strtotime('-1 day', $localNow));
        return [$day . ' 00:00:00', $day . ' 23:59:59'];
    }

    /**
     * The equivalent period immediately before the current window (for deltas).
     *
     * @return array [startDate, endDate]
     */
    private static function priorWindowFor(string $frequency): array
    {
        $localNow = current_time('timestamp');

        if ($frequency === 'weekly') {
            return [
                gmdate('Y-m-d 00:00:00', strtotime('-14 days', $localNow)),
                gmdate('Y-m-d 23:59:59', strtotime('-8 days', $localNow)),
            ];
        }

        if ($frequency === 'monthly') {
            $firstOfLastMonth = strtotime('first day of last month', $localNow);
            $firstOfPrevMonth = strtotime('first day of previous month', $firstOfLastMonth);
            return [
                gmdate('Y-m-01 00:00:00', $firstOfPrevMonth),
                gmdate('Y-m-t 23:59:59', $firstOfPrevMonth),
            ];
        }

        // daily — day before yesterday
        $day = gmdate('Y-m-d', strtotime('-2 days', $localNow));
        return [$day . ' 00:00:00', $day . ' 23:59:59'];
    }

    private static function resolveRecipients($raw, string $frequency, $config): array
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $raw)));

        $adminEmail = get_bloginfo('admin_email');

        $emails = [];
        foreach ($parts as $part) {
            if (strpos($part, '{{') !== false) {
                // Expand the admin-email smartcode (whitespace-tolerant) to the real
                // address, then resolve any other shortcodes via the parser. This
                // guarantees {{wp.admin_email}} becomes a real email before sending.
                $part = preg_replace('/\{\{\s*wp\.admin_email\s*\}\}/', $adminEmail, $part);
                if (strpos($part, '{{') !== false) {
                    $part = ShortcodeTemplateBuilder::make($part, []);
                }
            }

            $part = sanitize_email($part);
            if ($part && is_email($part)) {
                $emails[] = $part;
            }
        }

        $emails = array_values(array_unique($emails));

        $emails = apply_filters('fluent_cart/store_digest/recipients', $emails, [
            'frequency' => $frequency,
            'settings'  => $config,
        ]);

        return is_array($emails) ? $emails : [];
    }

    private static function renderEmailBody(array $payload): string
    {
        $body = (string) App::make('view')->make('emails.digest', [
            'digest' => $payload,
        ]);

        return (string) App::make('view')->make('emails.general_template', [
            'emailBody'   => $body,
            'preheader'   => self::preheader($payload),
            'header'      => '',
            'emailFooter' => (new EmailNotificationMailer())->getEmailFooter(),
        ]);
    }

    private static function preheader(array $payload): string
    {
        /* translators: %1$s: frequency (e.g. daily), %2$s: period label */
        return sprintf(
            __('Your %1$s store digest for %2$s', 'fluent-cart'),
            strtolower((string) Arr::get($payload, 'frequency_label', '')),
            (string) Arr::get($payload, 'period_label', '')
        );
    }

    /**
     * Friendly opening line, varied per cadence. Uses a numbered placeholder so
     * the store name slots in (`%1$s`) for translators.
     */
    private static function introLine(string $frequency, string $storeName): string
    {
        if ($frequency === 'weekly') {
            /* translators: %1$s: store name */
            return sprintf(__("Here's how %1\$s did over the last 7 days.", 'fluent-cart'), $storeName);
        }
        if ($frequency === 'monthly') {
            /* translators: %1$s: store name */
            return sprintf(__("Here's how %1\$s did last month.", 'fluent-cart'), $storeName);
        }
        /* translators: %1$s: store name */
        return sprintf(__("Here's how %1\$s did yesterday.", 'fluent-cart'), $storeName);
    }

    private static function subjectFor(array $payload): string
    {
        $storeName = (string) Arr::get($payload, 'store_name', '');
        $period = (string) Arr::get($payload, 'period_label', '');

        if (Arr::get($payload, 'frequency') === 'weekly') {
            /* translators: %1$s: store name, %2$s: period range */
            return sprintf(__('%1$s weekly digest — %2$s', 'fluent-cart'), $storeName, $period);
        }
        if (Arr::get($payload, 'frequency') === 'monthly') {
            /* translators: %1$s: store name, %2$s: month and year */
            return sprintf(__('%1$s monthly digest — %2$s', 'fluent-cart'), $storeName, $period);
        }
        /* translators: %1$s: store name, %2$s: date */
        return sprintf(__('%1$s daily digest — %2$s', 'fluent-cart'), $storeName, $period);
    }

    private static function frequencyLabel(string $frequency): string
    {
        if ($frequency === 'weekly') {
            return __('Weekly', 'fluent-cart');
        }
        if ($frequency === 'monthly') {
            return __('Monthly', 'fluent-cart');
        }
        return __('Daily', 'fluent-cart');
    }

    private static function periodLabel(string $frequency, string $start, string $end): string
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);

        if ($frequency === 'monthly') {
            return date_i18n('F Y', $startTs);
        }
        if ($frequency === 'weekly') {
            /* translators: %1$s: start date (e.g. May 19), %2$s: end date (e.g. May 25, 2026) */
            return sprintf(
                __('%1$s – %2$s', 'fluent-cart'),
                date_i18n('M j', $startTs),
                date_i18n('M j, Y', $endTs)
            );
        }
        return date_i18n('F j, Y', $startTs);
    }

    private static function storeName(): string
    {
        $name = (new StoreSettings())->get('store_name');
        if (empty($name)) {
            $name = get_bloginfo('name');
        }
        return (string) $name;
    }

    /**
     * Report totals are already decimals (SQL divides by 100), but
     * Helper::toDecimal expects integer cents — convert back before formatting.
     */
    private static function money($decimalAmount): string
    {
        $cents = (int) round(((float) $decimalAmount) * 100);
        return Helper::toDecimal($cents);
    }

    private static function fluctuation($fluctuations, string $key): float
    {
        return (float) Arr::get($fluctuations, $key, 0);
    }

    /**
     * Choose the Pro upsell angle for this digest and build its copy + tracked URL.
     *
     * Two layers:
     *  - Contextual (phase 2): pick the angle from THIS period's own numbers — a
     *    growing store, a quiet/declining one, and a high-volume one each hear a
     *    different, relevant pitch.
     *  - Rotation (phase 1): when no signal stands out, rotate through evergreen
     *    angles deterministically, offset per store, so a recurring digest never
     *    repeats the same line week after week (anti-fatigue) while still splitting
     *    the population across angles at any given moment (so the angles are
     *    measurable, not just decorative).
     *
     * Every CTA carries a utm_content of "{frequency}_{variant}" so conversions
     * are attributable per cadence and per angle.
     */
    private static function proPromo(array $payload): array
    {
        $metrics    = (array) Arr::get($payload, 'metrics', []);
        $flux       = (array) Arr::get($payload, 'fluctuations', []);
        $frequency  = (string) Arr::get($payload, 'frequency', 'daily');
        $grossDelta = (float) Arr::get($flux, 'gross_sale', 0);
        $orderCount = (int) Arr::get($metrics, 'order_count', 0);
        $isEmpty    = !empty($payload['is_empty']);

        if ($isEmpty || $grossDelta <= -10.0) {
            $variant = 'winback';   // quiet or shrinking period → win-back angle
        } elseif ($grossDelta >= 10.0 && $orderCount > 0) {
            $variant = 'growth';    // clear upward momentum → scale angle
        } elseif ($orderCount >= self::busyThreshold($frequency)) {
            $variant = 'automate';  // high volume → save-time angle
        } else {
            // No strong signal — rotate evergreen angles (per-store offset + period).
            $evergreen = ['insight', 'value', 'social'];
            $variant = $evergreen[self::rotationSeed($frequency) % count($evergreen)];
        }

        $copy = self::proPromoCopy($variant, $grossDelta, $orderCount);

        return [
            'variant' => $variant,
            'body'    => $copy['body'],
            'cta'     => $copy['cta'],
            'url'     => self::proPromoUrl($payload, $variant),
        ];
    }

    /**
     * Benefit-led copy per angle. All anchored to the same true Pro value
     * (premium payment gateways, integrations, customizations) but led by a
     * distinct motivational hook. <strong> tags are injected around the escaped
     * translatable text, so the returned body is safe HTML for the view to echo.
     *
     * @return array{body:string,cta:string}
     */
    private static function proPromoCopy(string $variant, float $grossDelta, int $orderCount): array
    {
        $cta = esc_html__('Explore FluentCart Pro →', 'fluent-cart');

        if ($variant === 'growth') {
            /* translators: %1$s: percentage increase, %2$s: opening <strong> tag, %3$s: closing </strong> tag */
            $body = sprintf(
                esc_html__('Your sales are up %1$s%% this period — and you\'re just getting started. %2$sFluentCart Pro%3$s adds premium payment gateways, integrations, and customizations to help you scale what\'s working.', 'fluent-cart'),
                esc_html(number_format_i18n(round($grossDelta))),
                '<strong>',
                '</strong>'
            );

            return ['body' => $body, 'cta' => $cta];
        }

        if ($variant === 'automate') {
            /* translators: %1$s: number of orders, %2$s: opening <strong> tag, %3$s: closing </strong> tag */
            $body = sprintf(
                esc_html__('You handled %1$s orders this period — that\'s a lot of moving parts. %2$sFluentCart Pro%3$s automates the busywork with premium integrations, gateways, and customizations so you can focus on growth.', 'fluent-cart'),
                esc_html(number_format_i18n($orderCount)),
                '<strong>',
                '</strong>'
            );

            return ['body' => $body, 'cta' => $cta];
        }

        if ($variant === 'winback') {
            /* translators: %1$s: opening <strong> tag, %2$s: closing </strong> tag */
            $body = sprintf(
                esc_html__('Every store has slower stretches. %1$sFluentCart Pro%2$s brings premium payment gateways, integrations, and customizations to help you win back momentum and turn more visits into sales.', 'fluent-cart'),
                '<strong>',
                '</strong>'
            );

            return ['body' => $body, 'cta' => $cta];
        }

        if ($variant === 'insight') {
            /* translators: %1$s: opening <strong> tag, %2$s: closing </strong> tag */
            $body = sprintf(
                esc_html__('Enjoying these insights? %1$sFluentCart Pro%2$s adds premium payment gateways, integrations, and customizations to help you act on them and grow your store.', 'fluent-cart'),
                '<strong>',
                '</strong>'
            );

            return ['body' => $body, 'cta' => $cta];
        }

        if ($variant === 'social') {
            /* translators: %1$s: opening <strong> tag, %2$s: closing </strong> tag */
            $body = sprintf(
                esc_html__('Growing stores run on %1$sFluentCart Pro%2$s — premium payment gateways, integrations, and customizations, all built to help you sell more with less effort.', 'fluent-cart'),
                '<strong>',
                '</strong>'
            );

            return ['body' => $body, 'cta' => $cta];
        }

        // 'value' — default evergreen.
        /* translators: %1$s: opening <strong> tag, %2$s: closing </strong> tag */
        $body = sprintf(
            esc_html__('Ready for more? %1$sFluentCart Pro%2$s brings premium payment gateways, integrations, and customizations to help your store grow.', 'fluent-cart'),
            '<strong>',
            '</strong>'
        );

        return ['body' => $body, 'cta' => $cta];
    }

    /**
     * Append campaign tracking so conversions are attributable per cadence and
     * per angle. Starts from the (already-filtered) pro_url carried in the payload.
     */
    private static function proPromoUrl(array $payload, string $variant): string
    {
        $base = (string) Arr::get($payload, 'pro_url', 'https://fluentcart.com/discount-deal/');

        return add_query_arg([
            'utm_source'   => 'fluent-cart',
            'utm_medium'   => 'email',
            'utm_campaign' => 'store_digest',
            'utm_content'  => (string) Arr::get($payload, 'frequency', 'daily') . '_' . $variant,
        ], $base);
    }

    /**
     * Deterministic rotation seed = per-store offset + period index. The offset
     * (hash of the site URL) puts each store on a different phase, so at any given
     * moment the population is split across angles; the period index advances each
     * cadence, so a single store cycles through angles over time.
     */
    private static function rotationSeed(string $frequency): int
    {
        $storeOffset = abs((int) crc32(home_url()));

        if ($frequency === 'weekly') {
            $periodIndex = (int) current_time('W'); // ISO-8601 week number
        } elseif ($frequency === 'monthly') {
            $periodIndex = (int) current_time('n'); // month, 1-12
        } else {
            $periodIndex = (int) current_time('z'); // day of year
        }

        return $storeOffset + $periodIndex;
    }

    /**
     * Order volume that counts as "busy" for the save-time angle, scaled by cadence.
     */
    private static function busyThreshold(string $frequency): int
    {
        if ($frequency === 'monthly') {
            return 200;
        }
        if ($frequency === 'weekly') {
            return 50;
        }
        return 10; // daily
    }
}
