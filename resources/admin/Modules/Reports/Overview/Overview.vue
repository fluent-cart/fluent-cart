<script setup>
import {computed, onMounted, onUnmounted, reactive, ref} from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";
import {formatNumber} from "../Utils/formatNumber";
import {channelLabels} from "../Sources/channelLabels";
import OverviewCards from "./OverviewCards.vue";
import OverviewTrendChart from "./OverviewTrendChart.vue";
import OverviewTopList from "./OverviewTopList.vue";
import ReportLoadError from "../Components/ReportLoadError.vue";
import {createRequestQueue} from "../Utils/requestQueue";

const props = defineProps({
  reportFilter: {type: Object, required: true},
});

// Card networks start to monitor a store at about this dispute rate
const DISPUTE_THRESHOLD = 0.75;

// Each section reads the endpoint its own report page uses, so the numbers match that page
const sources = reactive({});
const KEYS = ['revenue', 'customers', 'refunds', 'health', 'subscriptions', 'products', 'channels', 'locations', 'payments',
  'installments', 'yearly'];
KEYS.forEach(key => (sources[key] = {data: null, loading: true, error: false}));
const sequence = {};
// The last request of each section, so Retry runs it again with the same filters
const retries = {};

// At most three report requests run at once, in page order
const queue = createRequestQueue(3);

const load = (key, endpoint, params, options = {}) => {
  const {track = false, first = false} = options;
  const request = (sequence[key] = (sequence[key] || 0) + 1);
  sources[key].loading = true;
  sources[key].error = false;
  retries[key] = () => load(key, endpoint, params, options);

  return new Promise(resolve => {
    const task = () => Rest.get('reports/' + endpoint, {params})
      .then(response => {
        if (request === sequence[key]) {
          sources[key].data = response;
        }
        resolve(response);
        // The tracked call hands the response on: the filter bar reads its compare ranges
        return response;
      })
      // A failed section shows an error with Retry; the other sections still load
      .catch(() => {
        if (request === sequence[key]) {
          sources[key].data = null;
          sources[key].error = true;
        }
        resolve(null);
      })
      .finally(() => {
        if (request === sequence[key]) {
          sources[key].loading = false;
        }
      });
    const run = track ? () => {
      const call = task();
      props.reportFilter.trackCompareRange(call);
      return call;
    } : task;
    queue.add(run, first);
  });
};

// Sections below the fold load when they scroll near: a visitor who reads the cards and leaves costs fewer requests
const listsRef = ref(null);
const yearlyRef = ref(null);
const lazy = {lists: {seen: false, pending: null}, yearly: {seen: false, pending: null}};
const whenSeen = (name, loader) => {
  if (lazy[name].seen) {
    loader();
  } else {
    lazy[name].pending = loader;
  }
};
let observer = null;
const watchFold = () => {
  // Without IntersectionObserver (an old browser), everything loads at once
  if (typeof IntersectionObserver === 'undefined') {
    Object.keys(lazy).forEach(name => (lazy[name].seen = true));
    return;
  }
  observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      const name = entry.target.dataset.lazy;
      if (!entry.isIntersecting || !lazy[name] || lazy[name].seen) {
        return;
      }
      lazy[name].seen = true;
      observer.unobserve(entry.target);
      const pending = lazy[name].pending;
      lazy[name].pending = null;
      pending && pending();
    });
  }, {rootMargin: '300px'});
  [listsRef.value, yearlyRef.value].filter(Boolean).forEach(el => observer.observe(el));
};

const loadAll = (filters) => {
  // A filter change drops the requests still waiting for the old filters
  queue.clear();
  // Store-wide: the Overview has no product, order type or subscription filter, so one set on another page is dropped
  const {orderTypes, ...rest} = (filters || props.reportFilter.applicableFilters).params;
  const params = {...rest, variation_ids: [], subscriptionType: ''};
  const subscriptionsRequest = (sequence.subscriptions = (sequence.subscriptions || 0) + 1);
  sources.subscriptions.loading = true;

  // Cheapest and most read first: the sales cards and the trend
  load('revenue', 'revenue', {...params, groupKey: 'default'}, {track: true});
  load('customers', 'customer-overview', params);
  load('refunds', 'refunds-overview', params);
  // Then Needs Attention and the subscription cards. Health ignores the date range, so a short cache serves it
  const loadHealth = () => {
    load('health', 'subscription-health', {...params, cached: 1}).then(response => {
      if (subscriptionsRequest !== sequence.subscriptions) {
        return;
      }
      // The churn rate is the slowest read: only a store with subscriptions asks, in summary form, and last
      if (response && response.health && (response.health.subscriptions || response.health.failing.count)) {
        load('subscriptions', 'subscriptions-overview', {...params, summary_only: 1});
      } else {
        sources.subscriptions.data = null;
        sources.subscriptions.loading = false;
      }
    });
    // A retried health check still decides whether the churn rate loads
    retries.health = loadHealth;
  };
  loadHealth();
  load('installments', 'installments', params);
  load('payments', 'payment-methods', params);
  // Below the fold, once seen
  whenSeen('lists', () => {
    load('products', 'product-sales', params);
    load('locations', 'locations', {...params, level: 'country', address_type: 'billing'});
    load('channels', 'source-channels', params);
  });
  // 24 months of net sales as the Revenue report counts them; it ignores the date range, so a short cache serves it
  whenSeen('yearly', () => load('yearly', 'overview', {currency: params.currency, net_sales_only: 1, cached: 1}));
};

const failed = (keys) => keys.filter(key => sources[key].error);
const retry = (keys) => failed(keys).forEach(key => retries[key] && retries[key]());

const amount = (value) => props.reportFilter.formatAmount(value || 0);
const cents = (value) => props.reportFilter.formatAmount(value || 0, {cents: true});
const regionName = (code) => {
  try {
    return new Intl.DisplayNames([document.documentElement.lang || 'en'], {type: 'region'}).of(code) || code;
  } catch (e) {
    return code;
  }
};
// An amount changes as a share of itself, a rate in points; no previous figure, no change
const change = (now, before, points = false) => {
  if (before === null || before === undefined || now === null || now === undefined || (!points && !before)) {
    return null;
  }
  // `|| 0` turns a rounded -0 into 0
  return (points ? Math.round((now - before) * 10) / 10 : Math.round((now - before) / Math.abs(before) * 1000) / 10) || 0;
};
// null stays null: a period with no orders has no refund rate
const rate = (value) => (value === null || value === undefined ? null : Math.round(Number(value) * 100) / 100);
const hasPrevious = (data, key) => !!(data && data[key] && Object.keys(data[key]).length);

const salesCards = computed(() => {
  const revenue = sources.revenue.data || {};
  const s = revenue.summary || {};
  const p = hasPrevious(revenue, 'previousSummary') ? revenue.previousSummary : null;
  const customers = sources.customers.data || {};
  const c = customers.summary || {};
  const pc = customers.previous || null;
  const refunds = sources.refunds.data || {};
  const r = refunds.summary || {};
  const pr = hasPrevious(refunds, 'previousSummary') ? refunds.previousSummary : null;
  const aov = (summary) => (summary && summary.order_count ? summary.net_sales / summary.order_count : 0);

  return [
    {key: 'net_sales', title: translate('Net sales'), value: amount(s.net_sales), route: 'reports_revenue', money: true, failed: sources.revenue.error,
      change: p ? change(s.net_sales, p.net_sales) : null, previous: p ? p.net_sales : null,
      info: translate('OverviewRpt_Customers_Paid')},
    {key: 'orders', title: translate('Orders'), value: formatNumber(s.order_count || 0), route: 'reports_orders', failed: sources.revenue.error,
      change: p ? change(s.order_count, p.order_count) : null, previous: p ? p.order_count : null,
      info: translate('Paid orders placed in this period, renewals included.')},
    {key: 'aov', title: translate('Average order value'), value: amount(aov(s)), route: 'reports_orders', money: true, failed: sources.revenue.error,
      change: p ? change(aov(s), aov(p)) : null, previous: p ? aov(p) : null,
      info: translate('Net sales divided by orders.')},
    {key: 'new_customers', title: translate('New customers'), value: formatNumber(c.new_customers || 0), route: 'reports_customer', failed: sources.customers.error,
      change: pc ? change(c.new_customers, pc.new_customers) : null, previous: pc ? pc.new_customers : null,
      info: translate('CustomerRpt_Customers_Whose')},
    // The Customers report gives no previous repeat rate, so this card shows no change
    {key: 'repeat_rate', title: translate('Repeat purchase rate'), value: (c.repeat_rate || 0) + '%', route: 'reports_customer', change: null, failed: sources.customers.error,
      info: translate('CustomerRpt_Customers_Paid')},
    // The Refunds page's headline: the share of the period's orders refunded in part or full
    {key: 'refund_rate', title: translate('Refund rate'), value: (rate(r.refund_rate_cohort) || 0) + '%', route: 'reports_refunds', points: true, liability: true, failed: sources.refunds.error,
      change: pr ? change(rate(r.refund_rate_cohort), rate(pr.refund_rate_cohort), true) : null, previous: pr ? rate(pr.refund_rate_cohort) : null,
      info: translate('Orders in this period refunded in part or full, as on the Refunds report.')},
  ];
});
const salesLoading = computed(() => sources.revenue.loading || sources.customers.loading || sources.refunds.loading);
const CARD_SOURCES = ['revenue', 'customers', 'refunds', 'subscriptions'];
// Needs Attention reads these; one that failed means the list may miss a warning
const CHECK_SOURCES = ['health', 'payments', 'installments', 'refunds'];

const health = computed(() => (sources.health.data || {}).health || null);
const hasSubscriptions = computed(() => !!(health.value && (health.value.subscriptions || health.value.failing.count)));
const subscriptionCards = computed(() => {
  const h = health.value || {};
  const overview = sources.subscriptions.data || {};
  const period = overview.period || {};
  const before = overview.previousPeriod || null;

  return [
    {key: 'mrr', title: translate('MRR (now)'), value: amount(h.mrr), route: 'reports_subscriptions', change: null,
      info: translate('OverviewRpt_Monthly_Recurring')},
    {key: 'subscriptions', title: translate('Paying subscriptions (now)'), value: formatNumber(h.subscriptions || 0), route: 'reports_subscriptions', change: null,
      info: translate('OverviewRpt_Active_Subscriptions')},
    {key: 'churn_rate', title: translate('Churn rate'), value: (period.churn_rate || 0) + '%', route: 'reports_subscriptions', points: true, liability: true,
      loading: sources.subscriptions.loading, failed: sources.subscriptions.error,
      change: before ? change(period.churn_rate, before.churn_rate, true) : null, previous: before ? before.churn_rate : null,
      info: translate('OverviewRpt_Subscriptions_Ended')},
  ];
});

// Items that need the owner's attention; the card hides when none apply
const attention = computed(() => {
  const items = [];
  const h = health.value;
  if (h && h.failing && h.failing.count) {
    items.push({key: 'failing', route: 'reports_subscriptions',
      /* translators: %1$s - number of subscriptions, %2$s - monthly recurring revenue */
      text: translate('%1$s subscriptions have a failed renewal payment, with %2$s of MRR at risk.', formatNumber(h.failing.count), amount(h.failing.mrr))});
  }
  const payments = (sources.payments.data || {}).totals || {};
  if (payments.dispute_rate > DISPUTE_THRESHOLD) {
    items.push({key: 'disputes', route: 'reports_payment_methods',
      /* translators: %1$s - dispute rate, %2$s - the card networks' monitoring threshold */
      text: translate('OverviewRpt_Dispute_Rate', payments.dispute_rate + '%', DISPUTE_THRESHOLD + '%')});
  }
  const installments = (sources.installments.data || {}).summary || {};
  if (installments.overdue_count) {
    items.push({key: 'installments', route: 'reports_installments',
      /* translators: %1$s - number of installment plans, %2$s - amount still owed */
      text: translate('%1$s installment plans have a failed payment, with %2$s still owed.', formatNumber(installments.overdue_count), cents(installments.overdue))});
  }
  const refunds = sources.refunds.data || {};
  const now = rate((refunds.summary || {}).refund_rate_cohort) || 0;
  const before = hasPrevious(refunds, 'previousSummary') ? rate(refunds.previousSummary.refund_rate_cohort) : null;
  // A real rise, not noise: at least 2 points and half again the compared rate
  if (before !== null && before !== undefined && now - before >= 2 && now >= before * 1.5) {
    items.push({key: 'refunds', route: 'reports_refunds',
      /* translators: %1$s - refund rate now, %2$s - refund rate in the compared period */
      text: translate('The refund rate rose to %1$s from %2$s in the compared period.', now + '%', before + '%')});
  }

  return items;
});

const trend = computed(() => {
  const revenue = sources.revenue.data || {};
  const points = revenue.revenueReport || [];
  const previous = Array.isArray(revenue.previousMetrics) && revenue.previousMetrics.length ? revenue.previousMetrics : null;

  return {
    groups: points.map(point => point.group),
    current: points.map(point => Number(point.net_sales) || 0),
    previous: previous ? points.map((point, index) => Number((previous[index] || {}).net_sales) || 0) : null,
  };
});

const yearly = computed(() => {
  const months = ((sources.yearly.data || {}).data || {}).net_sales_months || [];
  const net = {};
  months.forEach(month => (net[month.group] = Number(month.net_sales) || 0));
  const last = months.slice(-12);

  return {
    groups: last.map(month => month.group),
    current: last.map(month => net[month.group] || 0),
    // The same month a year earlier
    previous: last.map(month => {
      const [year, number] = String(month.group).split('-');
      return net[(Number(year) - 1) + '-' + number] || 0;
    }),
  };
});

const topProducts = computed(() => {
  const data = sources.products.data || {};
  const total = (data.summary || {}).net || 0;
  return [...(data.products || [])].sort((a, b) => b.net - a.net).slice(0, 5)
    .map(row => ({key: row.product_id, label: row.product_title, value: cents(row.net), share: total ? row.net / total * 100 : 0}));
});
const topChannels = computed(() => {
  const labels = channelLabels();
  const channels = ((sources.channels.data || {}).channels || []).filter(row => row.customers > 0);
  const total = channels.reduce((sum, row) => sum + row.customers, 0);
  return [...channels].sort((a, b) => b.customers - a.customers).slice(0, 5)
    .map(row => ({key: row.key, label: row.label || labels[row.key] || row.key, value: formatNumber(row.customers), share: total ? row.customers / total * 100 : 0}));
});
const topCountries = computed(() => {
  const data = sources.locations.data || {};
  const total = (data.totals || {}).net_sales || 0;
  return (data.rows || []).filter(row => !row.unknown).slice(0, 5)
    .map(row => ({key: row.key, label: regionName(row.key), value: amount(row.net_sales), share: total ? row.net_sales / total * 100 : 0}));
});

onMounted(() => {
  // The fold watcher first: without IntersectionObserver it marks every section seen before anything loads
  watchFold();
  loadAll();
  props.reportFilter.addListener('overview', loadAll);
});
onUnmounted(() => {
  props.reportFilter.removeListener('overview');
  queue.clear();
  observer && observer.disconnect();
  // Late responses land nowhere
  Object.keys(sequence).forEach(key => sequence[key]++);
});
</script>

<template>
  <div class="report-overview-page">
    <OverviewCards :cards="salesCards" :loading="salesLoading"/>
    <!-- MRR and paying subscriptions show as soon as health is in; only the churn card waits for its slower read -->
    <OverviewCards v-if="hasSubscriptions" :cards="subscriptionCards"/>
    <Card.Container v-if="failed(CARD_SOURCES).length">
      <Card.Body>
        <ReportLoadError :message="translate('Some figures could not be loaded.')" @retry="retry(CARD_SOURCES)"/>
      </Card.Body>
    </Card.Container>

    <Card.Container v-if="attention.length || failed(CHECK_SOURCES).length">
      <Card.Header :title="translate('Needs Attention')" title_size="small" border_bottom/>
      <Card.Body>
        <ul class="list-none m-0 p-0">
          <li v-for="item in attention" :key="item.key" class="flex items-start gap-2 py-2 text-sm border-b border-gray-divider dark:border-dark-500 last:border-b-0">
            <DynamicIcon name="InformationFill" class="w-4 h-4 mt-0.5 text-warning-500 shrink-0" aria-hidden="true"/>
            <span class="flex-1 text-system-dark dark:text-gray-50">{{ item.text }}</span>
            <router-link :to="{name: item.route}" class="fct-text-link text-xs whitespace-nowrap">{{ translate('View report') }}</router-link>
          </li>
        </ul>
        <ReportLoadError v-if="failed(CHECK_SOURCES).length" :message="translate('Some checks could not run, so this list may be incomplete.')"
                         @retry="retry(CHECK_SOURCES)"/>
      </Card.Body>
    </Card.Container>

    <OverviewTrendChart
        :title="translate('Net Sales')"
        :text="translate('Net sales by period, and the compared period as a dotted line.')"
        :groups="trend.groups"
        :current="trend.current"
        :previous="trend.previous"
        :current-name="translate('This period')"
        :previous-name="translate('Compared period')"
        :loading="sources.revenue.loading"
        :failed="sources.revenue.error"
        :format-amount="reportFilter.formatAmount"
        @retry="retry(['revenue'])"
    />

    <div ref="listsRef" data-lazy="lists" class="grid gap-7.5 xl:grid-cols-3 mb-7.5">
      <OverviewTopList :title="translate('Top Products')" :text="translate('By net sales')" route="reports_products"
                       :rows="topProducts" :loading="sources.products.loading" :failed="sources.products.error" @retry="retry(['products'])" :empty="translate('No product sales in this period')"/>
      <OverviewTopList :title="translate('Top Channels')" :text="translate('By new customers')" route="reports_sources"
                       :rows="topChannels" :loading="sources.channels.loading" :failed="sources.channels.error" @retry="retry(['channels'])" :empty="translate('No new customers in this period')"/>
      <OverviewTopList :title="translate('Top Countries')" :text="translate('By net sales, billing address')" route="reports_locations"
                       :rows="topCountries" :loading="sources.locations.loading" :failed="sources.locations.error" @retry="retry(['locations'])" :empty="translate('No orders in this period')"/>
    </div>

    <div ref="yearlyRef" data-lazy="yearly">
      <OverviewTrendChart
          :title="translate('Last 12 Months')"
          :text="translate('OverviewRpt_Net_Sales')"
          :groups="yearly.groups"
          :current="yearly.current"
          :previous="yearly.previous"
          :current-name="translate('Last 12 months')"
          :previous-name="translate('A year earlier')"
          bars
          :loading="sources.yearly.loading"
          :failed="sources.yearly.error"
          :format-amount="reportFilter.formatAmount"
          @retry="retry(['yearly'])"
      />
    </div>
  </div>
</template>
