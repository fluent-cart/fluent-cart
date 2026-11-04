<script setup>
import { computed } from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import { formatNumber } from "../Utils/formatNumber";

// The Orders card split by type, status, discount and (physical stores only) shipping status
const props = defineProps({
  mix: {
    type: Object,
    default: null,
  },
  loading: {
    type: Boolean,
    default: false,
  },
});

// Status colours carry meaning (green done, amber waiting, red lost); types use the chart palette.
// Full class names, so Tailwind keeps them.
const COLORS = {
  types: {
    renewal: "bg-report-royal_blue",
    subscription: "bg-report-medium_turquoise",
    payment: "bg-report-golden_rod",
    installment: "bg-report-medium_slate_blue",
    installment_payment: "bg-report-coral",
  },
  statuses: {
    completed: "bg-success-500",
    processing: "bg-blue-500",
    "on-hold": "bg-warning-500",
    canceled: "bg-red-500",
    failed: "bg-red-700",
  },
  shipping: {
    delivered: "bg-success-500",
    shipped: "bg-blue-500",
    partially_shipped: "bg-report-deep_sky_blue",
    unshipped: "bg-warning-500",
    unshippable: "bg-gray-400",
  },
  discounted: {
    discounted: "bg-report-hot_pink",
    full_price: "bg-gray-200 dark:bg-dark-400",
  },
};
const FALLBACK = ["bg-report-coral", "bg-report-hot_pink", "bg-report-sea_green", "bg-gray-400"];

const share = (value) => {
  const number = Number(value) || 0;
  if (number > 0 && number < 0.1) {
    return "<0.1%";
  }

  return new Intl.NumberFormat(undefined, {maximumFractionDigits: 1}).format(number) + "%";
};

const withColors = (group, rows) => rows.map((row, index) => ({
  ...row,
  color: (COLORS[group] || {})[row.key] || FALLBACK[index % FALLBACK.length],
}));

const sections = computed(() => {
  const mix = props.mix || {};
  const discounted = mix.discounted || {orders: 0, share: 0};
  const list = [
    {key: "types", title: translate("Order type"), rows: withColors("types", mix.types || [])},
    {key: "statuses", title: translate("Order status"), rows: withColors("statuses", mix.statuses || [])},
    {
      key: "discounted",
      title: translate("Discount"),
      rows: withColors("discounted", [
        {key: "discounted", label: translate("Discounted"), orders: discounted.orders, share: discounted.share},
        {
          key: "full_price",
          label: translate("Full price"),
          orders: (mix.total || 0) - discounted.orders,
          share: mix.total ? Math.max(0, 100 - discounted.share) : 0,
        },
      ]),
      link: {to: {name: "reports_coupons"}, text: translate("Coupons report")},
    },
  ];
  // Digital-only stores have no shipping to show
  if (mix.fulfillment && mix.fulfillment.orders > 0) {
    list.push({
      key: "shipping",
      title: translate("Shipping"),
      /* translators: %1$s - number of physical orders */
      hint: translate("%1$s physical orders", formatNumber(mix.fulfillment.orders)),
      rows: withColors("shipping", mix.fulfillment.statuses || []),
    });
  }

  return list;
});
</script>

<template>
  <Card.Container class="fct-report-order-mix">
    <Card.Header title_size="small" border_bottom>
      <template #title>
        <div class="flex items-center gap-2">
          <h5 class="fct-card-header-title is-small">{{ translate("Order mix") }}</h5>
          <el-tooltip popper-class="fct-tooltip" placement="top"
                      :content="translate('OrderRpt_Orders_Orders')">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
              <DynamicIcon name="InformationFill" class="w-4 text-gray-500" aria-hidden="true" />
            </button>
          </el-tooltip>
        </div>
      </template>
    </Card.Header>
    <Card.Body>
      <el-skeleton v-if="loading" animated :rows="4" />

      <div v-else-if="!mix || !mix.total" class="text-sm text-system-light dark:text-gray-400">
        {{ translate("No orders in this period") }}
      </div>

      <div v-else class="fct-report-order-mix-grid" :class="'has-' + sections.length">
        <section v-for="section in sections" :key="section.key" class="fct-report-order-mix-section">
          <div class="fct-report-order-mix-head">
            <h6 class="fct-report-order-mix-title">{{ section.title }}</h6>
            <span v-if="section.hint" class="fct-report-order-mix-hint">{{ section.hint }}</span>
            <router-link v-else-if="section.link" :to="section.link.to" class="fct-report-order-mix-hint">{{ section.link.text }}</router-link>
          </div>

          <!-- One bar for the whole: each part is as wide as its share -->
          <div class="fct-report-order-mix-bar" role="img"
               :aria-label="section.rows.map(row => row.label + ' ' + share(row.share)).join(', ')">
            <span v-for="row in section.rows" :key="row.key" :class="row.color"
                  :style="{width: Math.max(0, Math.min(100, Number(row.share) || 0)) + '%'}"></span>
          </div>

          <ul class="fct-report-order-mix-legend">
            <li v-for="row in section.rows" :key="row.key">
              <span class="fct-report-order-mix-dot" :class="row.color" aria-hidden="true"></span>
              <span class="fct-report-order-mix-label">{{ row.label }}</span>
              <span class="fct-report-order-mix-count">{{ formatNumber(row.orders) }}</span>
              <span class="fct-report-order-mix-share">{{ share(row.share) }}</span>
            </li>
          </ul>
        </section>
      </div>
    </Card.Body>
  </Card.Container>
</template>
