<script setup>
import { computed } from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import { formatNumber } from "../Utils/formatNumber";
import reportFilter from "@/Models/Reports/ReportFilterModel";

// Net sales split by order type: for a subscription store, recurring vs new money
const props = defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  hasComparison: {
    type: Boolean,
    default: false,
  },
});

// Same colours as the Orders page's order mix, so a type reads the same everywhere
const COLORS = {
  renewal: "bg-report-royal_blue",
  subscription: "bg-report-medium_turquoise",
  payment: "bg-report-golden_rod",
    installment: "bg-report-medium_slate_blue",
    installment_payment: "bg-report-coral",
};

const tableRows = computed(() => props.rows.map(row => ({...row, color: COLORS[row.key] || "bg-gray-400"})));

// Currency signs arrive as HTML entities; decode so the amount renders as plain text
const money = (value) => new DOMParser().parseFromString(reportFilter.formatAmount(value || 0), "text/html").documentElement.textContent;
const share = (value) => new Intl.NumberFormat(undefined, {maximumFractionDigits: 1}).format(Number(value) || 0) + "%";
const changeClass = (value) => (value === null || value === undefined ? "text-system-light" : (value >= 0 ? "text-success-500" : "text-red-500"));
</script>

<template>
  <Card.Container class="fct-report-order-mix">
    <Card.Header title_size="small" border_bottom>
      <template #title>
        <div class="flex items-center gap-2">
          <h5 class="fct-card-header-title is-small">{{ translate("Net sales by order type") }}</h5>
          <el-tooltip popper-class="fct-tooltip" placement="top"
                      :content="translate('RevenueRpt_Time_Orders')">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
              <DynamicIcon name="InformationFill" class="w-4 text-gray-500" aria-hidden="true" />
            </button>
          </el-tooltip>
        </div>
      </template>
    </Card.Header>
    <Card.Body>
      <el-skeleton v-if="loading" animated :rows="3" />

      <div v-else-if="!tableRows.length" class="text-sm text-system-light dark:text-gray-400">
        {{ translate("No orders in this period") }}
      </div>

      <template v-else>
        <div class="fct-report-order-mix-bar" role="img" :aria-label="tableRows.map(row => row.label + ' ' + share(row.share)).join(', ')">
          <span v-for="row in tableRows" :key="row.key" :class="row.color"
                :style="{width: Math.max(0, Math.min(100, Number(row.share) || 0)) + '%'}"></span>
        </div>

        <el-table :data="tableRows" class="w-full">
          <el-table-column :label="translate('Order type')" min-width="160">
            <template #default="{row}">
              <span class="inline-flex items-center gap-2">
                <span class="fct-report-order-mix-dot" :class="row.color" aria-hidden="true"></span>
                {{ row.label }}
              </span>
            </template>
          </el-table-column>
          <el-table-column :label="translate('Orders')" min-width="100" align="right">
            <template #default="{row}">{{ formatNumber(row.orders) }}</template>
          </el-table-column>
          <el-table-column :label="translate('Net Sales')" min-width="120" align="right">
            <template #default="{row}"><span class="font-medium">{{ money(row.net_sales) }}</span></template>
          </el-table-column>
          <el-table-column :label="translate('Share')" min-width="90" align="right">
            <template #default="{row}">{{ share(row.share) }}</template>
          </el-table-column>
          <el-table-column :label="translate('Net Sales per Order')" min-width="140" align="right">
            <template #default="{row}">{{ money(row.average) }}</template>
          </el-table-column>
          <el-table-column v-if="hasComparison" :label="translate('Change (%)')" min-width="110" align="right">
            <template #default="{row}">
              <span dir="ltr" :class="changeClass(row.change)">
                {{ row.change === null || row.change === undefined ? 'n/a' : row.change.toFixed(2) + '%' }}
              </span>
            </template>
          </el-table-column>
        </el-table>
      </template>
    </Card.Body>
  </Card.Container>
</template>
