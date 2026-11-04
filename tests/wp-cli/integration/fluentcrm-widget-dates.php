<?php
/**
 * FluentCRM contact profile "Commerce Info" widget dates (FluentCRM board 23334).
 *
 * formatDateForFluentCrm() handed FluentCart's GMT dates to FluentCRM's
 * Helper::formatDateTime(), which reads its input as site time, so every date
 * was off by the site's UTC offset: on a UTC-5 site an order placed a minute
 * ago read "in 5 hours". It also called the helper whenever the Helper class
 * existed, but formatDateTime() only arrived in FluentCRM 3.2.0, so older
 * FluentCRM versions hit a fatal error on the contact profile.
 */

use FluentCart\App\Modules\Integrations\FluentPlugins\FluentCRMDeepIntegration;

return [
    [
        'id'            => 'fluentcrm-widget-dates',
        'name'          => 'FluentCRM widget shows GMT dates in site time',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            if (!method_exists('FluentCrm\\App\\Services\\Helper', 'formatDateTime')) {
                FcTest::skip('FluentCRM 3.2.0 or newer is not active on this install.');
                return;
            }
            if (\FluentCrm\App\Services\Helper::isExperimentalEnabled('classic_date_time')) {
                FcTest::skip('FluentCRM classic date/time display is on; this case checks the relative display.');
                return;
            }

            // Process-local UTC-5 site; nothing is written to the options table.
            $offset = function () {
                return -5;
            };
            $timezone = function () {
                return '';
            };
            add_filter('pre_option_gmt_offset', $offset);
            add_filter('pre_option_timezone_string', $timezone);

            try {
                $integration = new FluentCRMDeepIntegration();

                $minuteAgo = gmdate('Y-m-d H:i:s', time() - 60);
                $shown = $integration->formatDateForFluentCrm($minuteAgo);
                FcTest::assert(
                    strpos($shown, 'ago') !== false && strpos($shown, 'hour') === false,
                    'an order placed a minute ago reads "1 min ago" on a UTC-5 site, got: ' . $shown
                );

                $dayAgo = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
                FcTest::assertSame(
                    '1 day ago',
                    $integration->formatDateForFluentCrm($dayAgo),
                    'an order placed a day ago reads "1 day ago" on a UTC-5 site'
                );
            } finally {
                remove_filter('pre_option_gmt_offset', $offset);
                remove_filter('pre_option_timezone_string', $timezone);
            }
        },
    ],
];
