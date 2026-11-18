<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import dayjs from "dayjs";
import {resolveDateFormat, fluentDayjsLocale} from "@/utils/Utils";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Notify from "@/utils/Notify";
import {exportCSVWithColumns} from "@/utils/Export";
import translate, {translateNumber} from "@/utils/translator/Translator";
import { formatNumber } from "../Utils/formatNumber";

import { liabilityClass, maybeLiability } from "../Utils/liability";
import reportFilter from "@/Models/Reports/ReportFilterModel";

const byNumber = (key) => (a, b) => Number(a[key]) - Number(b[key]);
// Currency signs arrive as HTML entities; decode so the amount renders as plain text.
const decodeEntities = (html) => new DOMParser().parseFromString(html, "text/html").documentElement.textContent;
// null ('n/a') sorts below every number, so it stays at one end in both directions.
const byNullableNumber = (key) => (a, b) => {
  if (a[key] === null || b[key] === null) {
    return (b[key] === null) - (a[key] === null);
  }
  return a[key] - b[key];
};
</script>

<script>

export default {
  name: "RevenueReport",
  props: {
    revenueReport: {
      type: Array,
      required: true
    },
    appliedGroupKey: {
      type: String
    },
    previousMetrics: {
      type: Array,
      default: () => []
    },
    hasComparison: {
      type: Boolean,
      default: false
    },
    loading: {
      type: Boolean,
      default: true,
    },
  },
  computed: {
    // Period totals that decide which optional columns show
    tax_total() {
      return this.revenueReport.reduce((sum, item) => sum + Number(item.taxes_net || 0), 0);
    },
    shipping_total() {
      return this.revenueReport.reduce((sum, item) => sum + Number(item.shipping_net || 0), 0);
    },
    hasExtras() {
      return this.tax_total !== 0 || this.shipping_total !== 0
        || this.revenueReport.some(item => Number(item.fees || 0) !== 0);
    },

    dateFormat() {
      if (!this.revenueReport.length) return '';

      const parts = this.revenueReport[0]['group'].split('-');
      
      if (parts.length === 3) {
        return resolveDateFormat('date');
      } else if (parts.length === 2) {
        return resolveDateFormat('month_year');
      }
      
      return '';
    },

    isDayGroup() {
      return this.revenueReport.length > 0 && this.revenueReport[0]['group'].split('-').length === 3;
    },

    // The parent row already shows the year, so day rows drop it.
    rowDateFormat() {
      return this.isDayGroup ? resolveDateFormat('date_short') : this.dateFormat;
    },

    compareDateFormat() {
      return this.isDayGroup ? resolveDateFormat('date').replace('MMMM', 'MMM') : this.dateFormat;
    },

    tableData() {
      const groupedData = this.revenueReport.reduce((accumulator, item, index) => {
        const year = item.year;

        if (!accumulator[year]) {
          accumulator[year] = {
            year: year,
            order_count: 0,
            net_revenue: 0,
            gross_sale: 0,
            gross_sales: 0,
            discounts: 0,
            net_sales: 0,
            total_collected: 0,
            total_refunded_amount: 0,
            refunds: 0,
            tax_total: 0,
            taxes_net: 0,
            shipping_total: 0,
            shipping_net: 0,
            compare_revenue: null,
            items: []
          };
        }

        const previous = this.hasComparison ? this.previousMetrics[index] : null;
        const compareRevenue = previous ? Number(previous.net_sales) : null;

        if (previous) {
          accumulator[year].compare_revenue = (accumulator[year].compare_revenue ?? 0) + compareRevenue;
        }

        accumulator[year].order_count += Number(item.order_count);
        accumulator[year].net_revenue += Number(item.net_revenue);
        accumulator[year].gross_sale += Number(item.total_sales);
        accumulator[year].gross_sales += Number(item.gross_sales);
        accumulator[year].discounts += Number(item.discounts);
        accumulator[year].net_sales += Number(item.net_sales);
        accumulator[year].total_collected += Number(item.total_collected);
        accumulator[year].total_refunded_amount += Number(item.total_refunds);
        accumulator[year].refunds += Number(item.refunds);
        accumulator[year].tax_total += Number(item.total_tax);
        accumulator[year].taxes_net += Number(item.taxes_net);
        accumulator[year].shipping_total += Number(item.shipping_total);
        accumulator[year].shipping_net += Number(item.shipping_net);

        accumulator[year].items.push({
          ...item,
          compare_revenue: compareRevenue,
          compare_date: previous ? this.formatGroup(previous.group, this.compareDateFormat) : '',
          change_percent: this.changePercent(Number(item.net_sales), compareRevenue),
        });

        return accumulator;
      }, {});

      return Object.values(groupedData).map((row) => ({
        ...row,
        change_percent: this.changePercent(row.net_sales, row.compare_revenue),
      }));
    }
  },
  methods: {
    formatDate(date) {
      // Not toLocaleDateString(undefined, ...): `undefined` is the BROWSER's
      // locale and the options hardcode an English field order, so this
      // ignored the store's date format -- while formatGroup() a few lines up
      // already honoured it. One file, two answers.
      return dayjs(date).locale(fluentDayjsLocale()).format(resolveDateFormat('date'));
    },

    formatCurrency(value) {
      return reportFilter.formatAmount(value);
    },

    formatGroup(group, format) {
      if (!group) return '';
      return format ? translateNumber(dayjs(group).locale(fluentDayjsLocale()).format(format)) : group;
    },

    changePercent(current, previous) {
      return previous ? ((current - previous) / previous) * 100 : null;
    },

    changeClass(change) {
      if (change > 0) return 'text-success-500';
      if (change < 0) return 'text-red-500';
      return 'text-system-light';
    },

    // One row per period, with the compared net sales when a comparison is on, and a totals row
    exportCsv() {
      const keys = ['order_count', 'gross_sales', 'discounts', 'refunds', 'net_sales', 'shipping_net', 'fees', 'taxes_net', 'total_collected'];
      const columns = [{header: 'period', accessor: 'group'}, ...keys.map(key => ({header: key, accessor: key}))];
      if (this.hasComparison) {
        columns.push({header: 'compare_net_sales', accessor: 'compare_revenue'}, {header: 'change_percent', accessor: 'change_percent'});
      }
      const rows = this.tableData.flatMap(year => year.items);
      const total = {group: translate('Total')};
      keys.forEach(key => (total[key] = rows.reduce((sum, row) => sum + Number(row[key] || 0), 0)));
      if (this.hasComparison) {
        total.compare_revenue = rows.reduce((sum, row) => sum + Number(row.compare_revenue || 0), 0);
        total.change_percent = this.changePercent(total.net_sales, total.compare_revenue);
      }
      exportCSVWithColumns([...rows, total], columns, 'revenue.csv').catch(error => Notify.error(error.message));
    },

    liabilityClass,
    maybeLiability
  },
};
</script>

<template>
  <Card.Container class="overflow-hidden">
    <Card.Header :title="translate('Revenue Breakdown')" title_size="small">
      <template #action v-if="!loading">
        <div class="fct-btn-group sm">
          <fct-tooltip :content="translate('Export CSV')" placement="top">
            <IconButton tag="button" :disabled="!revenueReport.length" :aria-label="translate('Export CSV')" @click="exportCsv">
              <DynamicIcon name="Download"/>
            </IconButton>
          </fct-tooltip>
        </div>
      </template>
    </Card.Header>

    <Card.Body class="px-0 pb-0">
      <fct-skeleton v-if="loading" animated :rows="2" class="p-4" />

      <fct-table
        v-else
        :data="tableData"
        row-key="year"
        :default-expand-all="false"
        class="w-full"
      >
        <fct-table-column type="expand" v-if="dateFormat">
          <template #default="props">
            <fct-table
              :data="props.row.items"
              row-key="month"
              size="small"
              class="w-full fct-revenue-breakdown-inner"
            >
              <!-- Matches the outer expand column so each inner column sits under its outer one. -->
              <fct-table-column width="48" />

              <fct-table-column
                prop="group"
                :label="translate('Date')"
                min-width="80"
              >
                <template #default="scope">
                  {{ formatGroup(scope.row.group, rowDateFormat) }}
                </template>
              </fct-table-column>

              <fct-table-column
                prop="order_count"
                :label="translate('Order Count')"
                min-width="100"
                sortable
                :sort-method="byNumber('order_count')"
              >
                <template #default="scope">
                  {{ translateNumber(formatNumber(scope.row.order_count)) }}
                </template>
              </fct-table-column>

              <fct-table-column
                prop="gross_sales"
                :label="translate('Gross Sales')"
                min-width="100"
                sortable
                :sort-method="byNumber('gross_sales')"
              >
                <template #default="scope">
                  <div class="inline-flex">{{ decodeEntities(formatCurrency(Number(scope.row.gross_sales))) }}</div>
                </template>
              </fct-table-column>

              <fct-table-column :label="translate('Discounts')" min-width="100" sortable :sort-method="byNumber('discounts')">
                <template #default="scope">
                  <div class="inline-flex" :class="liabilityClass(scope.row.discounts)">{{ decodeEntities(maybeLiability(scope.row.discounts, null, reportFilter.reportCurrency)) }}</div>
                </template>
              </fct-table-column>

              <fct-table-column

                :label="translate('Refunds')"
                min-width="100"
                sortable
                :sort-method="byNumber('refunds')"
              >
                <template #default="scope">
                  <div class="inline-flex" :class="liabilityClass(scope.row.refunds)" v-html="maybeLiability(scope.row.refunds, null, reportFilter.reportCurrency)">

                  </div>
                </template>
              </fct-table-column>

              <fct-table-column prop="net_sales" :label="translate('Net Sales')" min-width="100" sortable :sort-method="byNumber('net_sales')">
                <template #default="scope">
                  <div class="inline-flex font-medium">{{ decodeEntities(formatCurrency(Number(scope.row.net_sales))) }}</div>
                </template>
              </fct-table-column>

              <fct-table-column
                prop="taxes_net"
                :label="translate('Taxes')"
                min-width="100"
                sortable
                :sort-method="byNumber('taxes_net')"
                v-if="tax_total > 0"
              >
                <template #default="scope">
                  <div class="inline-flex" :class="liabilityClass(scope.row.taxes_net)">
                    {{ maybeLiability(scope.row.taxes_net, null, reportFilter.reportCurrency) }}
                  </div>
                </template>
              </fct-table-column>

              <fct-table-column
                prop="shipping_net"
                :label="translate('Shipping')"
                min-width="100"
                sortable
                :sort-method="byNumber('shipping_net')"
                v-if="shipping_total > 0"
              >
                <template #default="scope">
                  <div class="inline-flex" :class="liabilityClass(scope.row.shipping_net)">
                    {{ maybeLiability(scope.row.shipping_net, null, reportFilter.reportCurrency) }}
                  </div>
                </template>
              </fct-table-column>

              <fct-table-column
                v-if="hasExtras"
                prop="total_collected"
                :label="translate('Total Sales')"
                min-width="100"
                sortable
                :sort-method="byNumber('total_collected')"
              >
                <template #default="scope">
                  <div class="inline-flex">{{ decodeEntities(formatCurrency(Number(scope.row.total_collected))) }}</div>
                </template>
              </fct-table-column>

              <fct-table-column
                v-if="hasComparison"
                prop="compare_revenue"
                :label="translate('Compare Net Sales')"
                min-width="100"
                sortable
                :sort-method="byNullableNumber('compare_revenue')"
              >
                <template #default="scope">
                  <fct-tooltip
                      v-if="scope.row.compare_revenue !== null"
                      :content="
                        /* translators: %s - date the row is compared with, e.g. Aug 17, 2026 */
                        translate('Compared with %s', scope.row.compare_date)
                      "
                      placement="top"
                  >
                    <div class="inline-flex cursor-pointer">{{ decodeEntities(formatCurrency(scope.row.compare_revenue)) }}</div>
                  </fct-tooltip>
                  <span v-else class="text-system-light">n/a</span>
                </template>
              </fct-table-column>

              <fct-table-column
                v-if="hasComparison"
                prop="change_percent"
                :label="translate('Change (%)')"
                min-width="110"
                sortable
                :sort-method="byNullableNumber('change_percent')"
              >
                <template #default="scope">
                  <span dir="ltr" :class="changeClass(scope.row.change_percent)">
                    {{ scope.row.change_percent === null ? 'n/a' : scope.row.change_percent.toFixed(2) + '%' }}
                  </span>
                </template>
              </fct-table-column>
            </fct-table>
          </template>
        </fct-table-column>

        <fct-table-column
          prop="year"
          :label="translate('Year')"
          min-width="80"
        />

        <fct-table-column
          prop="order_count"
          :label="translate('Order Count')"
          min-width="100"
          sortable
          :sort-method="byNumber('order_count')"
        >
          <template #default="scope">
            {{ translateNumber(formatNumber(scope.row.order_count)) }}
          </template>
        </fct-table-column>

        <fct-table-column
          prop="gross_sales"
          :label="translate('Gross Sales')"
          min-width="100"
          sortable
          :sort-method="byNumber('gross_sales')"
        >
          <template #default="scope">
            <div class="inline-flex">{{ decodeEntities(formatCurrency(scope.row.gross_sales)) }}</div>
          </template>
        </fct-table-column>

        <fct-table-column :label="translate('Discounts')" min-width="100" sortable :sort-method="byNumber('discounts')">
          <template #default="scope">
            <div class="inline-flex" :class="liabilityClass(scope.row.discounts)">{{ decodeEntities(maybeLiability(scope.row.discounts, null, reportFilter.reportCurrency)) }}</div>
          </template>
        </fct-table-column>

        <fct-table-column
          :label="translate('Refunds')"
          min-width="100"
          sortable
          :sort-method="byNumber('refunds')"
        >
          <template #default="scope">
            <div class="inline-flex" :class="liabilityClass(scope.row.refunds)" v-html="maybeLiability(scope.row.refunds, null, reportFilter.reportCurrency)">

            </div>
          </template>
        </fct-table-column>

        <fct-table-column prop="net_sales" :label="translate('Net Sales')" min-width="100" sortable :sort-method="byNumber('net_sales')">
          <template #default="scope">
            <div class="inline-flex font-medium">{{ decodeEntities(formatCurrency(scope.row.net_sales)) }}</div>
          </template>
        </fct-table-column>

        <fct-table-column prop="taxes_net" :label="translate('Taxes')" min-width="100" sortable :sort-method="byNumber('taxes_net')" v-if="tax_total > 0">
          <template #default="scope">
            <div class="inline-flex" v-html="formatCurrency(scope.row.taxes_net)">

            </div>
          </template>
        </fct-table-column>

        <fct-table-column
          prop="shipping_net"
          :label="translate('Shipping')"
          min-width="100"
          sortable
          :sort-method="byNumber('shipping_net')"
          v-if="shipping_total > 0"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="formatCurrency(scope.row.shipping_net)">

            </div>
          </template>
        </fct-table-column>

        <fct-table-column
          v-if="hasExtras"
          prop="total_collected"
          :label="translate('Total Sales')"
          min-width="100"
          sortable
          :sort-method="byNumber('total_collected')"
        >
          <template #default="scope">
            <div class="inline-flex">{{ decodeEntities(formatCurrency(scope.row.total_collected)) }}</div>
          </template>
        </fct-table-column>

        <fct-table-column
          v-if="hasComparison"
          prop="compare_revenue"
          :label="translate('Compare Net Sales')"
          min-width="100"
          sortable
          :sort-method="byNullableNumber('compare_revenue')"
        >
          <template #default="scope">
            <div v-if="scope.row.compare_revenue !== null" class="inline-flex">{{ decodeEntities(formatCurrency(scope.row.compare_revenue)) }}</div>
            <span v-else class="text-system-light">n/a</span>
          </template>
        </fct-table-column>

        <fct-table-column
          v-if="hasComparison"
          prop="change_percent"
          :label="translate('Change (%)')"
          min-width="110"
          sortable
          :sort-method="byNullableNumber('change_percent')"
        >
          <template #default="scope">
            <span dir="ltr" :class="changeClass(scope.row.change_percent)">
              {{ scope.row.change_percent === null ? 'n/a' : scope.row.change_percent.toFixed(2) + '%' }}
            </span>
          </template>
        </fct-table-column>

        <template #empty>
          <el-empty :description="translate('No data available')" />
        </template>
      </fct-table>
    </Card.Body>
  </Card.Container>
</template>
