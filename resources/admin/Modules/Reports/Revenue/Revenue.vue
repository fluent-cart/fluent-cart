<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import dayjs from "dayjs";
import {resolveDateFormat, fluentDayjsLocale} from "@/utils/Utils";
import ExportReport from "../ExportReport.vue";
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
  name: "Revenue",
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
            total_refunded_amount: 0,
            tax_total: 0,
            shipping_total: 0,
            compare_revenue: null,
            items: []
          };
        }

        const previous = this.hasComparison ? this.previousMetrics[index] : null;
        const compareRevenue = previous ? Number(previous.net_revenue) : null;

        if (previous) {
          accumulator[year].compare_revenue = (accumulator[year].compare_revenue ?? 0) + compareRevenue;
        }

        accumulator[year].order_count += Number(item.order_count);
        accumulator[year].net_revenue += Number(item.net_revenue);
        accumulator[year].gross_sale += Number(item.total_sales);
        accumulator[year].total_refunded_amount += Number(item.total_refunds);
        accumulator[year].tax_total += Number(item.total_tax);
        accumulator[year].shipping_total += Number(item.shipping_total);

        accumulator[year].items.push({
          ...item,
          compare_revenue: compareRevenue,
          compare_date: previous ? this.formatGroup(previous.group, this.compareDateFormat) : '',
          change_percent: this.changePercent(Number(item.net_revenue), compareRevenue),
        });

        return accumulator;
      }, {});

      return Object.values(groupedData).map((row) => ({
        ...row,
        change_percent: this.changePercent(row.net_revenue, row.compare_revenue),
      }));
    }
  },
  data() {
    return {

    };
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

    liabilityClass,
    maybeLiability
  },
};
</script>

<template>
  <Card.Container class="overflow-hidden">
    <Card.Header :title="translate('Revenue Breakdown')" title_size="small">
      <template #action v-if="!loading">
        <ExportReport :data="revenueReport" filename="revenue.csv" />
      </template>
    </Card.Header>

    <Card.Body class="px-0 pb-0">
      <el-skeleton v-if="loading" animated :rows="2" class="p-4" />

      <el-table
        v-else
        :data="tableData"
        row-key="year"
        :default-expand-all="false"
        class="w-full"
      >
        <el-table-column type="expand" v-if="dateFormat">
          <template #default="props">
            <el-table
              :data="props.row.items"
              row-key="month"
              size="small"
              class="w-full fct-revenue-breakdown-inner"
            >
              <!-- Matches the outer expand column so each inner column sits under its outer one. -->
              <el-table-column width="48" />

              <el-table-column
                prop="group"
                :label="translate('Date')"
                min-width="80"
              >
                <template #default="scope">
                  {{ formatGroup(scope.row.group, rowDateFormat) }}
                </template>
              </el-table-column>

              <el-table-column
                prop="order_count"
                :label="translate('Order Count')"
                min-width="100"
                sortable
                :sort-method="byNumber('order_count')"
              >
                <template #default="scope">
                  {{ translateNumber(formatNumber(scope.row.order_count)) }}
                </template>
              </el-table-column>

              <el-table-column
                prop="total_sales"
                :label="translate('Gross Sales')"
                min-width="100"
                sortable
                :sort-method="byNumber('total_sales')"
              >
                <template #default="scope">

                  <div class="inline-flex" v-html="formatCurrency(Number(scope.row.total_sales)) ">

                  </div>
                </template>
              </el-table-column>

              <el-table-column

                :label="translate('Refunds')"
                min-width="100"
                sortable
                :sort-method="byNumber('total_refunds')"
              >
                <template #default="scope">
                  <div class="inline-flex" :class="liabilityClass(scope.row.total_refunds)" v-html="maybeLiability(scope.row.total_refunds, null, reportFilter.reportCurrency)">

                  </div>
                </template>
              </el-table-column>

              <el-table-column
                prop="total_tax"
                :label="translate('Taxes')"
                min-width="100"
                sortable
                :sort-method="byNumber('total_tax')"
                v-if="tax_total > 0"
              >
                <template #default="scope">
                  <div class="inline-flex" :class="liabilityClass(scope.row.total_tax)">
                    {{ maybeLiability(scope.row.total_tax, null, reportFilter.reportCurrency) }}
                  </div>
                </template>
              </el-table-column>

              <el-table-column
                prop="shipping_total"
                :label="translate('Shipping')"
                min-width="100"
                sortable
                :sort-method="byNumber('shipping_total')"
                v-if="shipping_total > 0"
              >
                <template #default="scope">
                  <div class="inline-flex" :class="liabilityClass(scope.row.shipping_total)">
                    {{ maybeLiability(scope.row.shipping_total, null, reportFilter.reportCurrency) }}
                  </div>
                </template>
              </el-table-column>

              <el-table-column
                prop="net_revenue"
                :label="translate('Revenue')"
                min-width="100"
                sortable
                :sort-method="byNumber('net_revenue')"
              >
                <template #default="scope">
                  <div class="inline-flex" v-html="formatCurrency(Number(scope.row.net_revenue))">

                  </div>
                </template>
              </el-table-column>

              <el-table-column
                v-if="hasComparison"
                prop="compare_revenue"
                :label="translate('Compare Revenue')"
                min-width="100"
                sortable
                :sort-method="byNullableNumber('compare_revenue')"
              >
                <template #default="scope">
                  <el-tooltip
                      v-if="scope.row.compare_revenue !== null"
                      popper-class="fct-tooltip"
                      :content="
                        /* translators: %s - date the row is compared with, e.g. Aug 17, 2026 */
                        translate('Compared with %s', scope.row.compare_date)
                      "
                      placement="top"
                  >
                    <div class="inline-flex cursor-pointer">{{ decodeEntities(formatCurrency(scope.row.compare_revenue)) }}</div>
                  </el-tooltip>
                  <span v-else class="text-system-light">n/a</span>
                </template>
              </el-table-column>

              <el-table-column
                v-if="hasComparison"
                prop="change_percent"
                :label="translate('Change') + ' (%)'"
                min-width="110"
                sortable
                :sort-method="byNullableNumber('change_percent')"
              >
                <template #default="scope">
                  <span :class="changeClass(scope.row.change_percent)">
                    {{ scope.row.change_percent === null ? 'n/a' : scope.row.change_percent.toFixed(2) + '%' }}
                  </span>
                </template>
              </el-table-column>
            </el-table>
          </template>
        </el-table-column>

        <el-table-column
          prop="year"
          :label="translate('Year')"
          min-width="80"
        />

        <el-table-column
          prop="order_count"
          :label="translate('Order Count')"
          min-width="100"
          sortable
          :sort-method="byNumber('order_count')"
        >
          <template #default="scope">
            {{ translateNumber(formatNumber(scope.row.order_count)) }}
          </template>
        </el-table-column>

        <el-table-column
          prop="gross_sale"
          :label="translate('Gross Sales')"
          min-width="100"
          sortable
          :sort-method="byNumber('gross_sale')"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="formatCurrency(scope.row.gross_sale)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          :label="translate('Refunds')"
          min-width="100"
          sortable
          :sort-method="byNumber('total_refunded_amount')"
        >
          <template #default="scope">
            <div class="inline-flex" :class="liabilityClass(scope.row.total_refunded_amount)" v-html="maybeLiability(scope.row.total_refunded_amount, null, reportFilter.reportCurrency)">

            </div>
          </template>
        </el-table-column>

        <el-table-column prop="tax_total" :label="translate('Taxes')" min-width="100" sortable :sort-method="byNumber('tax_total')" v-if="tax_total > 0">
          <template #default="scope">
            <div class="inline-flex" v-html="formatCurrency(scope.row.tax_total)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="shipping_total"
          :label="translate('Shipping')"
          min-width="100"
          sortable
          :sort-method="byNumber('shipping_total')"
          v-if="shipping_total > 0"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="formatCurrency(scope.row.shipping_total)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="net_revenue"
          :label="translate('Revenue')"
          min-width="100"
          sortable
          :sort-method="byNumber('net_revenue')"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="formatCurrency(scope.row.net_revenue)">
            </div>
          </template>
        </el-table-column>

        <el-table-column
          v-if="hasComparison"
          prop="compare_revenue"
          :label="translate('Compare Revenue')"
          min-width="100"
          sortable
          :sort-method="byNullableNumber('compare_revenue')"
        >
          <template #default="scope">
            <div v-if="scope.row.compare_revenue !== null" class="inline-flex">{{ decodeEntities(formatCurrency(scope.row.compare_revenue)) }}</div>
            <span v-else class="text-system-light">n/a</span>
          </template>
        </el-table-column>

        <el-table-column
          v-if="hasComparison"
          prop="change_percent"
          :label="translate('Change') + ' (%)'"
          min-width="110"
          sortable
          :sort-method="byNullableNumber('change_percent')"
        >
          <template #default="scope">
            <span :class="changeClass(scope.row.change_percent)">
              {{ scope.row.change_percent === null ? 'n/a' : scope.row.change_percent.toFixed(2) + '%' }}
            </span>
          </template>
        </el-table-column>

        <template #empty>
          <el-empty :description="translate('No data available')" />
        </template>
      </el-table>
    </Card.Body>
  </Card.Container>
</template>
