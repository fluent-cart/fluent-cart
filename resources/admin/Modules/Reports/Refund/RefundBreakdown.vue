<script setup>
import { computed } from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import reportFilter from "@/Models/Reports/ReportFilterModel";

// Where the refunded money went: full vs partial, sale type and payment method (refund date, same pattern as Order mix)
const props = defineProps({
  overview: {type: Object, default: null},
  loading: {type: Boolean, default: false},
});

// Sale types keep their Order mix colours; full refunds read stronger than partial ones
const COLORS = {
  full: "bg-red-500",
  partial: "bg-warning-500",
  renewal: "bg-report-royal_blue",
  subscription: "bg-report-medium_turquoise",
  payment: "bg-report-golden_rod",
  installment: "bg-report-medium_slate_blue",
  installment_payment: "bg-report-coral",
};
const FALLBACK = ["bg-report-deep_sky_blue", "bg-report-sea_green", "bg-report-hot_pink", "bg-report-purple", "bg-gray-400"];

const money = (value) => new DOMParser().parseFromString(reportFilter.formatAmount(value || 0), "text/html").documentElement.textContent;
const share = (value) => {
  const number = Number(value) || 0;
  return number > 0 && number < 0.1 ? "<0.1%" : new Intl.NumberFormat(undefined, {maximumFractionDigits: 1}).format(number) + "%";
};
const label = (key) => (key ? key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, " ") : key);

const withShares = (rows) => {
  const total = rows.reduce((sum, row) => sum + Number(row.amount || 0), 0);

  return rows.map((row, index) => ({
    ...row,
    color: COLORS[row.key] || FALLBACK[index % FALLBACK.length],
    share: total ? Number(row.amount) / total * 100 : 0,
  }));
};

const sections = computed(() => {
  const o = props.overview || {};
  const s = o.summary || {};

  return [
    {
      key: "kind",
      title: translate("Full or partial"),
      rows: withShares([
        {key: "full", label: translate("Full refunds"), amount: (s.full || {}).amount || 0, count: (s.full || {}).count || 0},
        {key: "partial", label: translate("Partial refunds"), amount: (s.partial || {}).amount || 0, count: (s.partial || {}).count || 0},
      ]),
    },
    {
      key: "sale_type",
      title: translate("Sale type"),
      rows: withShares((o.by_sale_type || []).map(row => ({...row, count: row.refunds}))),
    },
    {
      key: "method",
      title: translate("Payment method"),
      rows: withShares((o.by_payment_method || []).map(row => ({...row, label: label(row.key), count: row.refunds}))),
    },
  ];
});
</script>

<template>
  <Card.Container class="fct-report-order-mix">
    <Card.Header title_size="small" border_bottom>
      <template #title>
        <div class="flex items-center gap-2">
          <h5 class="fct-card-header-title is-small">{{ translate("Refund breakdown") }}</h5>
          <el-tooltip popper-class="fct-tooltip" placement="top"
                      :content="translate('RefundRpt_Refunded_Amount')">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
              <DynamicIcon name="InformationFill" class="w-4 text-gray-500" aria-hidden="true" />
            </button>
          </el-tooltip>
        </div>
      </template>
    </Card.Header>
    <Card.Body>
      <el-skeleton v-if="loading" animated :rows="4" />

      <div v-else-if="!overview || !overview.summary || !overview.summary.refunds" class="text-sm text-system-light dark:text-gray-400">
        {{ translate("No refunds in this period") }}
      </div>

      <div v-else class="fct-report-order-mix-grid has-3">
        <section v-for="section in sections" :key="section.key" class="fct-report-order-mix-section">
          <div class="fct-report-order-mix-head">
            <h6 class="fct-report-order-mix-title">{{ section.title }}</h6>
          </div>
          <div class="fct-report-order-mix-bar" role="img" :aria-label="section.rows.map(row => row.label + ' ' + share(row.share)).join(', ')">
            <span v-for="row in section.rows" :key="row.key" :class="row.color" :style="{width: Math.max(0, Math.min(100, row.share)) + '%'}"></span>
          </div>
          <ul class="fct-report-order-mix-legend">
            <li v-for="row in section.rows" :key="row.key">
              <span class="fct-report-order-mix-dot" :class="row.color" aria-hidden="true"></span>
              <span class="fct-report-order-mix-label">{{ row.label }}</span>
              <span class="fct-report-order-mix-count">{{ money(row.amount) }}</span>
              <span class="fct-report-order-mix-share">{{ share(row.share) }}</span>
            </li>
          </ul>
        </section>
      </div>
    </Card.Body>
  </Card.Container>
</template>
