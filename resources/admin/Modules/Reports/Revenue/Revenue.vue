<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import dayjs from "dayjs";
import ExportReport from "../ExportReport.vue";
import translateNumber from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import { formatNumber } from "../Utils/formatNumber";

import { liabilityClass, maybeLiability } from "../Utils/liability";
</script>

<script>
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

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
        return 'MMMM DD, YYYY';
      } else if (parts.length === 2) {
        return 'MMMM YYYY';
      }
      
      return '';
    },

    tableData() {
      const groupedData = this.revenueReport.reduce((accumulator, item) => {
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
            items: []
          };
        }

        accumulator[year].order_count += Number(item.order_count);
        accumulator[year].net_revenue += Number(item.net_revenue);
        accumulator[year].gross_sale += Number(item.total_sales);
        accumulator[year].total_refunded_amount += Number(item.total_refunds);
        accumulator[year].tax_total += Number(item.total_tax);
        accumulator[year].shipping_total += Number(item.shipping_total);

        accumulator[year].items.push(item);

        return accumulator;
      }, {});

      return Object.values(groupedData);
    }
  },
  data() {
    return {

    };
  },
  methods: {
    formatDate(date) {
      const options = { year: "numeric", month: "long", day: "numeric" };
      return new Date(date).toLocaleDateString(undefined, options);
    },

    formatCurrency(value) {
      return CurrencyFormatter.scaled(value);
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
              border
              class="w-full"
            >
              <el-table-column
                prop="group"
                :label="translate('Date')"
                min-width="160"
              >
                <template #default="scope">
                  {{ dayjs(scope.row.group).format(dateFormat) }}
                </template>
              </el-table-column>

              <el-table-column
                prop="order_count"
                :label="translate('Order Count')"
                min-width="140"
              >
                <template #default="scope">
                  {{ translateNumber(formatNumber(scope.row.order_count)) }}
                </template>
              </el-table-column>

              <el-table-column
                prop="total_sales"
                :label="translate('Gross Sales')"
                min-width="150"
              >
                <template #default="scope">

                  <div class="inline-flex" v-html="formatCurrency(Number(scope.row.total_sales)) ">

                  </div>
                </template>
              </el-table-column>

              <el-table-column

                :label="translate('Refunds')"
                min-width="150"
              >
                <template #default="scope">
                  <div class="inline-flex" :class="liabilityClass(scope.row.total_refunds)" v-html="maybeLiability(scope.row.total_refunds)">

                  </div>
                </template>
              </el-table-column>

              <el-table-column
                prop="total_tax"
                :label="translate('Taxes')"
                min-width="150"
                v-if="tax_total > 0"
              >
                <template #default="scope">
                  <div class="inline-flex" :class="liabilityClass(scope.row.total_tax)">
                    {{ maybeLiability(scope.row.total_tax) }}
                  </div>
                </template>
              </el-table-column>

              <el-table-column
                prop="shipping_total"
                :label="translate('Shipping')"
                min-width="150"
                v-if="shipping_total > 0"
              >
                <template #default="scope">
                  <div class="inline-flex" :class="liabilityClass(scope.row.shipping_total)">
                    {{ maybeLiability(scope.row.shipping_total) }}
                  </div>
                </template>
              </el-table-column>

              <el-table-column
                prop="net_revenue"
                :label="translate('Revenue')"
                min-width="180"
              >
                <template #default="scope">
                  <div class="inline-flex" v-html="formatCurrency(Number(scope.row.net_revenue))">

                  </div>
                </template>
              </el-table-column>
            </el-table>
          </template>
        </el-table-column>

        <el-table-column
          prop="year"
          :label="translate('Year')"
          min-width="50"
          fixed
        />

        <el-table-column
          prop="order_count"
          :label="translate('Order Count')"
          min-width="120"
        >
          <template #default="scope">
            {{ translateNumber(formatNumber(scope.row.order_count)) }}
          </template>
        </el-table-column>

        <el-table-column
          prop="gross_sale"
          :label="translate('Gross Sales')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="formatCurrency(scope.row.gross_sale)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          :label="translate('Refunds')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex" :class="liabilityClass(scope.row.total_refunded_amount)" v-html="maybeLiability(scope.row.total_refunded_amount)">

            </div>
          </template>
        </el-table-column>

        <el-table-column prop="tax_total" :label="translate('Taxes')" min-width="150" v-if="tax_total > 0">
          <template #default="scope">
            <div class="inline-flex" v-html="formatCurrency(scope.row.tax_total)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="shipping_total"
          :label="translate('Shipping')"
          min-width="150"
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
          min-width="180"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="formatCurrency(scope.row.net_revenue)">
            </div>
          </template>
        </el-table-column>

        <template #empty>
          <el-empty :description="translate('No data available')" />
        </template>
      </el-table>
    </Card.Body>
  </Card.Container>
</template>
