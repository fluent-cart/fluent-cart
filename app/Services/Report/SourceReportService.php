<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Database\Query\Builder as Query;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

class SourceReportService extends ReportService
{
    // Today's view; the API default when no group_by is sent
    public const DEFAULT_GROUP = 'campaign_source_medium';

    public const UTM_GROUPS = [
        'campaign'               => ['utm_campaign'],
        'source'                 => ['utm_source'],
        'medium'                 => ['utm_medium'],
        'source_medium'          => ['utm_source', 'utm_medium'],
        'term'                   => ['utm_term'],
        'content'                => ['utm_content'],
        'campaign_source_medium' => ['utm_campaign', 'utm_source', 'utm_medium'],
    ];

    public const OTHER_GROUPS = ['product', 'variation', 'day', 'week', 'month', 'referrer'];

    public const NONE_KEY = '__none__';

    public static function sanitizeGroupBy($value): string
    {
        $value = is_string($value) ? $value : '';

        return isset(self::UTM_GROUPS[$value]) || in_array($value, self::OTHER_GROUPS, true) ? $value : self::DEFAULT_GROUP;
    }

    /**
     * Grouped rows as a collection of row objects, as before; the default grouping keeps its keys.
     *
     * @param array $params processed report params plus group_by and include_none
     * @return Collection
     */
    public function getSourceReportData($params = [])
    {
        return new Collection(array_map(function ($row) {
            return (object) $row;
        }, $this->getReport($params)['rows']));
    }

    /**
     * @return array{rows: array, totals: array, group_by: string}
     */
    public function getReport(array $params): array
    {
        $groupBy = self::sanitizeGroupBy(Arr::get($params, 'group_by'));
        $includeNone = !empty($params['include_none']);

        $rows = $this->groupRows($params, $groupBy);

        if ($includeNone) {
            $none = $this->finishMetrics($this->metricsRow($this->filteredOrders($params, $groupBy, false)->selectRaw($this->metricsSelect($params))->first()));
            if ($none['orders']) {
                $rows[] = array_merge($none, ['group_key' => self::NONE_KEY, 'utm_key' => self::NONE_KEY, 'label' => __('(none)', 'fluent-cart'), 'is_none' => true]);
            }
        }

        // Totals from the order set itself, never by adding rows: a multi-item order sits in several product rows
        $totals = $this->finishMetrics($this->metricsRow($this->filteredOrders($params, $groupBy, $includeNone ? null : true)->selectRaw($this->metricsSelect($params))->first()));

        return ['rows' => $rows, 'totals' => $totals, 'group_by' => $groupBy];
    }

    /**
     * Orders in the report scope with the shared report filters and the Pro advanced filters applied.
     *
     * @param bool|null $withSource true: orders that carry the grouped source data; false: those that do not;
     *                              null: both
     */
    private function filteredOrders(array $params, string $groupBy, $withSource)
    {
        // One operations row per order (the latest): the schema does not make it unique, and a second row
        // would count the order twice. A grouped table of the range's orders, not a per-order subquery
        $latest = ReportHelper::rangeOrderRows('fct_order_operations', 'ro_op', $params)
            ->selectRaw('ro_op.order_id, MAX(ro_op.id) AS id')
            ->groupBy('ro_op.order_id');

        $query = ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params)
            ->leftJoinSub($latest, 'lo', 'lo.order_id', '=', 'o.id')
            ->leftJoin('fct_order_operations as oo', 'oo.id', '=', 'lo.id')
            ->leftJoinSub($this->laterPaymentsQuery($params), 'lp', 'lp.parent_id', '=', 'o.id');

        $hasSource = $groupBy === 'referrer'
            ? "COALESCE(oo.refer_url, '') <> ''"
            : "COALESCE(oo.utm_source, '') <> ''";

        if ($withSource === true) {
            $query->whereRaw($hasSource);
        } elseif ($withSource === false) {
            $query->whereRaw("NOT ({$hasSource})");
        }

        $query = $this->applyFilters($query, $params);

        /**
         * Let listeners narrow the Order Sources report query.
         *
         * The free plugin only forwards the request params ('filter_type' and the
         * raw 'advanced_filters' JSON among them) — it never parses or applies the
         * advanced filters itself. FluentCart Pro owns that: it reads the params,
         * builds the condition groups through the Orders filter engine and adds
         * the constraints to this query. The query reads orders as `o` with
         * fct_order_operations joined as `oo`; it is not grouped yet, and every
         * row, the totals and the "(none)" row are built from it.
         *
         * @param \FluentCart\Framework\Database\Query\Builder $query
         * @param array $params Processed report params.
         */
        $filteredQuery = apply_filters('fluent_cart/report/sources_query', $query, $params);

        // A callback that returns something other than a query builder (null,
        // an array, a thrown-away clone of the wrong type) would fatal the whole
        // report, so fall back to the unmodified query instead.
        return $filteredQuery instanceof Query ? $filteredQuery : $query;
    }

    /**
     * Net of later payments per first order: renewal orders whose parent_id is that order, any date.
     */
    private function laterPaymentsQuery(array $params)
    {
        $netSql = ReportHelper::netSalesSql($params);

        // Only renewals of the range's orders: the report joins later payments to those
        $rangeOrders = function ($q) use ($params) {
            $q->select('lpo.id')->from('fct_orders as lpo')->whereBetween('lpo.created_at', [$params['startDate'], $params['endDate']]);
        };

        return ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), [], [], $rangeOrders)
            ->selectRaw("o.parent_id, SUM({$netSql}) AS later_net, COUNT(*) AS later_orders")
            ->whereIn('o.parent_id', $rangeOrders)
            ->whereIn('o.payment_status', Status::getReportStatuses())
            ->groupBy('o.parent_id');
    }

    /**
     * Metric columns in cents. $share splits an order across its lines for product groupings.
     */
    private function metricsSelect(array $params, string $share = '1'): string
    {
        $netSql = ReportHelper::netSalesSql($params);
        $firstOrder = "o.type IN ('" . Status::ORDER_TYPE_PAYMENT . "', '" . Status::ORDER_TYPE_SUBSCRIPTION . "')";

        return "COUNT(DISTINCT o.id) AS orders,
            COUNT(DISTINCT CASE WHEN o.total_refund > 0 THEN o.id END) AS refunded_orders,
            COUNT(DISTINCT CASE WHEN {$firstOrder} THEN o.id END) AS first_orders,
            SUM(o.total_paid * {$share}) AS gross,
            SUM(o.total_refund * {$share}) AS refunds,
            SUM({$netSql} * {$share}) AS net,
            SUM(CASE WHEN {$firstOrder} THEN {$netSql} * {$share} ELSE 0 END) AS first_net,
            SUM(CASE WHEN {$firstOrder} THEN COALESCE(lp.later_net, 0) * {$share} ELSE 0 END) AS later_net";
    }

    /**
     * Rows of the chosen grouping, before the optional "(none)" row.
     */
    private function groupRows(array $params, string $groupBy): array
    {
        if (isset(self::UTM_GROUPS[$groupBy])) {
            return $this->utmRows($params, self::UTM_GROUPS[$groupBy]);
        }

        if ($groupBy === 'product' || $groupBy === 'variation') {
            return $this->productRows($params, $groupBy);
        }

        if ($groupBy === 'referrer') {
            return $this->simpleRows($params, $groupBy, $this->referrerSql());
        }

        $formats = ['day' => '%Y-%m-%d', 'week' => '%x-W%v', 'month' => '%Y-%m'];

        $local = ReportHelper::storeTimeSql('o.created_at', $params['startDate'], $params['endDate']);

        return $this->simpleRows($params, $groupBy, "DATE_FORMAT({$local}, '{$formats[$groupBy]}')");
    }

    /**
     * UTM groupings: case-insensitive, with "_", "-" and spaces treated alike; each row shows the most
     * common original spelling. SQL groups by key and spelling; the spellings merge here.
     */
    private function utmRows(array $params, array $fields): array
    {
        $select = [];
        $groups = [];
        foreach ($fields as $index => $field) {
            $key = self::normalizedSql("oo.{$field}");
            $select[] = "{$key} AS k{$index}, COALESCE(oo.{$field}, '') AS v{$index}";
            $groups[] = $key;
            $groups[] = "COALESCE(oo.{$field}, '')";
        }

        $result = $this->filteredOrders($params, 'utm', true)
            ->selectRaw(implode(', ', $select) . ', ' . $this->metricsSelect($params))
            ->groupByRaw(implode(', ', $groups))
            ->get();

        $merged = [];
        $spellings = [];
        foreach ($result as $row) {
            $parts = [];
            foreach ($fields as $index => $field) {
                $parts[] = (string) $row->{'k' . $index};
            }
            $key = implode('|', $parts);
            $metrics = $this->metricsRow($row);

            if (!isset($merged[$key])) {
                $merged[$key] = $metrics;
            } else {
                $merged[$key] = $this->addMetrics($merged[$key], $metrics);
            }

            foreach ($fields as $index => $field) {
                $spelling = (string) $row->{'v' . $index};
                $spellings[$key][$field][$spelling] = ($spellings[$key][$field][$spelling] ?? 0) + $metrics['orders'];
            }
        }

        $rows = [];
        foreach ($merged as $key => $metrics) {
            $row = ['group_key' => $key, 'utm_key' => $key];
            foreach ($fields as $field) {
                arsort($spellings[$key][$field]);
                $row[$field] = (string) array_key_first($spellings[$key][$field]);
            }
            $row['label'] = implode(' / ', array_map(function ($field) use ($row) {
                return $row[$field] !== '' ? $row[$field] : __('(not set)', 'fluent-cart');
            }, $fields));
            $rows[] = array_merge($row, $this->finishMetrics($metrics));
        }

        return $this->sortRows($rows);
    }

    /**
     * Product or variation rows. An order is shared across its lines by line amount (equally when its lines
     * total 0), so a multi-item order adds up to its own money across rows.
     */
    private function productRows(array $params, string $groupBy): array
    {
        $column = $groupBy === 'product' ? 'oi.post_id' : 'oi.object_id';
        // Same source as the joined lines, so the share is a ratio of like amounts in the ALL mode too
        $items = ReportCurrency::isConverted($params)
            ? App::db()->query()->fromSub(ReportCurrency::convertedOrderItems(), 'li')
            : App::db()->table('fct_order_items as li');
        $lineSums = $items
            ->selectRaw('li.order_id, SUM(li.line_total) AS line_sum, COUNT(*) AS line_count')
            ->groupBy('li.order_id');
        $share = '(CASE WHEN ols.line_sum > 0 THEN oi.line_total / ols.line_sum ELSE 1 / ols.line_count END)';

        $query = $this->filteredOrders($params, $groupBy, true);
        $query = ReportCurrency::joinOrderItems($query, $params)
            ->joinSub($lineSums, 'ols', 'ols.order_id', '=', 'o.id');

        $result = $query
            ->selectRaw("{$column} AS group_id, MAX(oi.post_title) AS product_title, " . $this->metricsSelect($params, $share))
            ->groupByRaw($column)
            ->get();

        $titles = [];
        if ($groupBy === 'variation') {
            $titles = App::db()->table('fct_product_variations')
                ->whereIn('id', $result->pluck('group_id')->all())
                ->pluck('variation_title', 'id')
                ->all();
        }

        $rows = [];
        foreach ($result as $row) {
            $id = (int) $row->group_id;
            $label = $id ? (string) $row->product_title : __('(no product)', 'fluent-cart');
            if ($groupBy === 'variation' && $id) {
                $label .= isset($titles[$id]) ? ' / ' . $titles[$id] : '';
            }
            $rows[] = array_merge([
                'group_key' => (string) $id,
                'utm_key'   => (string) $id,
                'group_id'  => $id,
                'label'     => $label,
            ], $this->finishMetrics($this->metricsRow($row)));
        }

        return $this->sortRows($rows);
    }

    /**
     * Rows grouped by one SQL expression (period or referrer domain).
     */
    private function simpleRows(array $params, string $groupBy, string $expression): array
    {
        $result = $this->filteredOrders($params, $groupBy, true)
            ->selectRaw("{$expression} AS group_value, " . $this->metricsSelect($params))
            ->groupByRaw($expression)
            ->get();

        $rows = [];
        foreach ($result as $row) {
            $value = (string) $row->group_value;
            if ($value === '') {
                $label = __('(not set)', 'fluent-cart');
            } elseif ($value === 'Organic search') {
                $label = __('Organic search', 'fluent-cart');
            } else {
                $label = $value;
            }
            $rows[] = array_merge([
                'group_key' => $value,
                'utm_key'   => $value,
                'label'     => $label,
                'is_unset'  => $value === '',
            ], $this->finishMetrics($this->metricsRow($row)));
        }

        // Periods read in time order; everything else by gross
        if (in_array($groupBy, ['day', 'week', 'month'], true)) {
            usort($rows, function ($a, $b) {
                return strcmp($a['group_key'], $b['group_key']);
            });

            return $rows;
        }

        return $this->sortRows($rows);
    }

    /**
     * Referrer domain: host without "www.", search engines as "Organic search", Google sign-in pages ignored.
     */
    private function referrerSql(): string
    {
        $domain = self::referrerDomainSql();

        return "(CASE
            WHEN {$domain} = 'accounts.google.com' THEN ''
            WHEN " . self::searchDomainSql($domain) . " THEN 'Organic search'
            ELSE {$domain}
        END)";
    }

    /**
     * The referrer's host, lower case, without "www."
     */
    private static function referrerDomainSql(): string
    {
        $host = "LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(TRIM(COALESCE(oo.refer_url, '')), 'https://', ''), 'http://', ''), '/', 1), CHAR(63), 1), ':', 1))";

        return "(CASE WHEN {$host} LIKE 'www.%' THEN SUBSTRING({$host}, 5) ELSE {$host} END)";
    }

    /**
     * A search engine's domain, shared by the channels and the referrer grouping
     */
    private static function searchDomainSql(string $domain): string
    {
        return "({$domain} REGEXP '(^|\\\\.)(google|bing|duckduckgo|yahoo|yandex|baidu|ecosia)\\\\.' OR {$domain} = 'search.brave.com')";
    }

    /** Channels in display order */
    public const CHANNELS = ['email', 'paid', 'social', 'ai', 'search', 'referral', 'campaign', 'direct'];

    /**
     * The order's channel: the store's own rules first (ChannelRules), then its UTM tags, then its referrer: email,
     * paid, social, AI assistants, a tagged campaign, search, another website, or direct (no source, a referrer from
     * the store or its own domains, or the Google sign-in page a visitor returns from)
     *
     * @param array $bindings receives the custom rules' values, in the order the SQL uses them
     */
    private static function channelSql(array $custom, array &$bindings): string
    {
        $medium = "LOWER(TRIM(COALESCE(oo.utm_medium, '')))";
        $source = "LOWER(TRIM(COALESCE(oo.utm_source, '')))";
        $domain = self::referrerDomainSql();
        // The store's own rules come first; its own domains count as direct, like the store itself
        [$customSql, $customBindings] = ChannelRules::whenSql($custom['rules'], [
            'source' => $source, 'medium' => $medium, 'campaign' => "LOWER(TRIM(COALESCE(oo.utm_campaign, '')))", 'domain' => $domain,
        ]);
        [$ownSql, $ownBindings] = ChannelRules::ownDomainSql($custom['own_domains'], $domain);
        // The custom branches come first in the CASE, the own domains later
        $bindings = array_merge($customBindings, $ownBindings);
        $site = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $site = esc_sql(strpos($site, 'www.') === 0 ? substr($site, 4) : $site);
        $socialSites = "'facebook.com', 'm.facebook.com', 'l.facebook.com', 'lm.facebook.com', 't.co', 'twitter.com', 'x.com', 'linkedin.com',
            'lnkd.in', 'youtube.com', 'm.youtube.com', 'reddit.com', 'instagram.com', 'l.instagram.com', 'pinterest.com', 'tiktok.com'";
        $socialSources = "'facebook', 'fb', 'twitter', 'x', 'linkedin', 'youtube', 'reddit', 'instagram', 'pinterest', 'tiktok'";
        // ChatGPT tags its links with utm_source=chatgpt.com; the others send a referrer
        $aiSites = "'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'copilot.microsoft.com', 'you.com'";

        return "(CASE{$customSql}
            WHEN {$medium} IN ('email', 'e-mail', 'newsletter') OR {$source} IN ('fluentcrm', 'fluent crm', 'newsletter', 'mailchimp', 'email') THEN 'email'
            WHEN {$medium} IN ('cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paid_social', 'paid-social', 'display', 'ads', 'ad') THEN 'paid'
            WHEN {$medium} IN ('social', 'social-media', 'social_media', 'sm') OR {$source} IN ({$socialSources})
                OR ({$source} = '' AND {$domain} IN ({$socialSites})) THEN 'social'
            WHEN {$source} IN ({$aiSites}) OR ({$source} = '' AND {$domain} IN ({$aiSites})) THEN 'ai'
            WHEN {$medium} = 'organic' THEN 'search'
            WHEN {$medium} = 'referral' THEN 'referral'
            WHEN {$source} <> '' THEN 'campaign'
            WHEN {$domain} = 'accounts.google.com' OR {$domain} = '{$site}'" . ($ownSql ? " OR {$ownSql}" : '') . " THEN 'direct'
            WHEN {$domain} LIKE 'mail.%' OR {$domain} LIKE 'webmail.%' OR {$domain} = 'outlook.live.com' THEN 'email'
            WHEN " . self::searchDomainSql($domain) . " THEN 'search'
            WHEN {$domain} <> '' AND {$domain} <> '{$site}' THEN 'referral'
            ELSE 'direct'
        END)";
    }

    /**
     * Customers won by channel: each new customer's first purchase in the range (the Customers report rule),
     * with first-order net, lifetime value to date and repeat rate (both in the report currency), refund rate,
     * tracking coverage and new customers by channel per period. Amounts in cents.
     */
    public function getChannels(array $params): array
    {
        $net = ReportHelper::netSalesSql($params);
        // Custom channel rules come from FluentCart Pro; without it every order gets a built-in channel
        $custom = ChannelRules::sanitize((array) apply_filters('fluent_cart/report/channel_rules', ['rules' => [], 'own_domains' => []]));
        $channelBindings = [];
        $channel = self::channelSql($custom, $channelBindings);
        // Custom channels first, in rule order, then the built-in ones
        $labels = [];
        foreach ($custom['rules'] as $rule) {
            $labels[ChannelRules::key($rule)] = $rule['name'];
        }
        $keys = array_merge(array_keys($labels), self::CHANNELS);
        $chartParams = $this->clampParamsToToday($params);
        $group = ReportHelper::processGroup($chartParams['startDate'], $chartParams['endDate'], $chartParams['groupKey']);

        $firsts = function () use ($params, $net, $channel, $channelBindings, $group) {
            return $this->filteredOrders($params, 'utm', null)
                ->where('o.total_paid', '>', 0)
                ->whereNotNull('o.customer_id')
                ->whereRaw(ReportHelper::firstPurchaseSql($params))
                ->selectRaw("{$group['by']} AS period, o.customer_id, {$channel} AS channel, {$net} AS net, o.total_refund > 0 AS refunded", $channelBindings);
        };

        // Each won customer's spend to date and purchases, in the report currency
        $won = App::db()->query()->fromSub($firsts(), 'w')->select('w.customer_id');
        $lifetime = ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), [], clone $won)
            ->where('o.total_paid', '>', 0)
            ->when(!empty($params['paymentStatus']), fn($q) => $q->whereIn('o.payment_status', $params['paymentStatus']))
            ->when(!empty($params['currency']) && $params['currency'] !== ReportCurrency::ALL, fn($q) => $q->where('o.currency', $params['currency']))
            ->whereIn('o.customer_id', $won)
            ->selectRaw("o.customer_id, SUM({$net}) AS lifetime, SUM(o.type <> '" . Status::ORDER_TYPE_RENEWAL . "') AS purchases")
            ->groupBy('o.customer_id');

        $rows = App::db()->query()->fromSub($firsts(), 'f')
            ->leftJoinSub($lifetime, 'lt', 'lt.customer_id', '=', 'f.customer_id')
            ->selectRaw('f.channel, COUNT(DISTINCT f.customer_id) AS customers, COUNT(*) AS orders, SUM(f.net) AS first_net,
                SUM(COALESCE(lt.lifetime, 0)) AS lifetime, SUM(lt.purchases >= 2) AS repeaters, SUM(f.refunded) AS refunded')
            ->groupBy('f.channel')
            ->get()
            ->keyBy('channel');

        $total = 0;
        $totalNet = 0;
        foreach ($rows as $row) {
            $total += (int) $row->customers;
            $totalNet += (int) $row->first_net;
        }

        $channels = [];
        foreach ($keys as $key) {
            $row = $rows->get($key);
            $customers = $row ? (int) $row->customers : 0;
            $orders = $row ? (int) $row->orders : 0;
            $channels[] = [
                'key'         => $key,
                // A custom channel's name; a built-in channel is named by the admin app
                'label'       => $labels[$key] ?? null,
                'customers'   => $customers,
                'share'       => $total ? round($customers / $total * 100, 1) : 0.0,
                'first_net'   => $row ? (int) $row->first_net : 0,
                'average_ltv' => $customers ? (int) round((int) $row->lifetime / $customers) : 0,
                'repeat_rate' => $customers ? round((int) $row->repeaters / $customers * 100, 1) : 0.0,
                'refund_rate' => $orders ? round((int) $row->refunded / $orders * 100, 1) : 0.0,
            ];
        }

        $direct = $rows->get('direct');
        $tracked = $total - ($direct ? (int) $direct->customers : 0);
        $trackedNet = $totalNet - ($direct ? (int) $direct->first_net : 0);

        $chart = $this->getPeriodRange($chartParams['startDate'], $chartParams['endDate'], $group['key'], $keys);
        foreach (App::db()->query()->fromSub($firsts(), 'f')
            ->selectRaw('f.period, f.channel, COUNT(DISTINCT f.customer_id) AS customers')
            ->groupBy('f.period', 'f.channel')
            ->get() as $row) {
            if (isset($chart[$row->period])) {
                $chart[$row->period][$row->channel] = (int) $row->customers;
            }
        }

        return [
            'coverage' => [
                'customers'     => $total,
                'tracked'       => $tracked,
                'share'         => $total ? round($tracked / $total * 100, 1) : 0.0,
                'revenue_share' => $totalNet > 0 ? round($trackedNet / $totalNet * 100, 1) : 0.0,
            ],
            'channels' => $channels,
            'chart'    => array_values($chart),
        ];
    }

    private static function normalizedSql(string $column): string
    {
        return "LOWER(TRIM(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), '_', ' '), '-', ' '), '  ', ' ')))";
    }

    private function metricsRow($row): array
    {
        $keys = ['orders', 'refunded_orders', 'first_orders', 'gross', 'refunds', 'net', 'first_net', 'later_net'];
        $metrics = [];
        foreach ($keys as $key) {
            $metrics[$key] = $row ? (float) $row->{$key} : 0.0;
        }

        return $metrics;
    }

    private function addMetrics(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $a[$key] += $value;
        }

        return $a;
    }

    /**
     * Report shape: amounts in currency units (as before), plus averages and the refund rate.
     */
    private function finishMetrics(array $m): array
    {
        $orders = (int) round($m['orders']);

        return [
            'orders'            => $orders,
            'refunded_orders'   => (int) round($m['refunded_orders']),
            'gross_sales'       => round($m['gross'] / 100, 2),
            'refunds'           => round($m['refunds'] / 100, 2),
            'net_sales'         => round($m['net'] / 100, 2),
            'average_order'     => $orders ? round($m['gross'] / $orders / 100, 2) : 0,
            'average_net_order' => $orders ? round($m['net'] / $orders / 100, 2) : 0,
            'refund_rate'       => $orders ? round($m['refunded_orders'] / $orders * 100, 2) : 0,
            'first_order_net'   => round($m['first_net'] / 100, 2),
            'later_net'         => round($m['later_net'] / 100, 2),
            'lifetime_net'      => round(($m['first_net'] + $m['later_net']) / 100, 2),
        ];
    }

    public const SORTABLE = ['orders', 'gross_sales', 'net_sales', 'refunds', 'refund_rate', 'average_order', 'average_net_order',
        'first_order_net', 'later_net', 'lifetime_net'];

    /**
     * One page of report rows, searched and sorted over the whole list first. The rows are built in PHP (spellings
     * merge there), so the page is cut here; the response and the comparison stay one page long.
     *
     * @param array $options {page, per_page, search, sort_by, sort_order}
     * @return array{rows: array, total: int}
     */
    public function pageRows(array $rows, array $options): array
    {
        $rows = $this->searchRows($rows, (string) ($options['search'] ?? ''));

        $sortBy = (string) ($options['sort_by'] ?? '');
        if (in_array($sortBy, self::SORTABLE, true)) {
            $direction = ($options['sort_order'] ?? '') === 'asc' ? 1 : -1;
            // Ties keep the default order
            $indexed = array_map(null, $rows, array_keys($rows));
            usort($indexed, function ($a, $b) use ($sortBy, $direction) {
                return (($a[0][$sortBy] ?? 0) <=> ($b[0][$sortBy] ?? 0)) * $direction ?: $a[1] <=> $b[1];
            });
            $rows = array_column($indexed, 0);
        }

        $perPage = (int) $options['per_page'];

        return [
            'rows'  => array_slice($rows, ((int) $options['page'] - 1) * $perPage, $perPage),
            'total' => count($rows),
        ];
    }

    /**
     * Rows whose label holds the search text, in any letter case
     */
    public function searchRows(array $rows, string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return $rows;
        }
        $find = function_exists('mb_stripos') ? 'mb_stripos' : 'stripos';

        return array_values(array_filter($rows, function ($row) use ($search, $find) {
            return $find((string) ($row['label'] ?? ''), $search) !== false;
        }));
    }

    private function sortRows(array $rows): array
    {
        usort($rows, function ($a, $b) {
            return $b['gross_sales'] <=> $a['gross_sales'] ?: strcmp($a['group_key'], $b['group_key']);
        });

        return $rows;
    }

    /**
     * Previous-period values per row, matched on group_key (utm_key) for any grouping.
     */
    public function calculateFluctuations($currentData, $comparisonData)
    {
        $comparisonLookup = [];
        foreach ($comparisonData as $item) {
            $comparisonLookup[$item['utm_key']] = $item;
        }

        $fields = ['orders', 'gross_sales', 'net_sales', 'average_order', 'average_net_order'];
        $fluctuations = [];

        foreach ($currentData as $currentItem) {
            $key = $currentItem['utm_key'];
            $comparisonItem = $comparisonLookup[$key] ?? null;
            $entry = [];

            foreach ($fields as $field) {
                $previous = $comparisonItem ? (float) $comparisonItem[$field] : 0;
                $entry['previous_' . $field] = $previous;
                // A group new in this period reads as +100%
                $entry[$field . '_fluctuation'] = $comparisonItem
                    ? $this->calculatePercentageChange($previous, $currentItem[$field])
                    : 100;
            }

            $fluctuations[$key] = $entry;
        }

        return $fluctuations;
    }

    protected function calculatePercentageChange($oldValue, $newValue)
    {
        if ($oldValue == 0) {
            return $newValue > 0 ? 100 : 0;
        }

        return round((($newValue - $oldValue) / $oldValue) * 100, 2);
    }
}
