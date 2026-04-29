<template>
  <div class="fct-revenue-comparison">
    <CardContainer class="overflow-hidden">
      <CardHeader :title="translate('Revenue Comparison')" title_size="small" />
      <CardBody class="px-0 pb-0">
        <el-skeleton v-if="loading" animated :rows="7" class="p-4" />

        <el-table v-else :data="comparisonData" class="w-full" maxHeight="390">
          <el-table-column :label="translate('Period')">
            <template #default="scope">
              {{ scope.row.group }}
            </template>
          </el-table-column>

          <el-table-column :label="translate('Current Revenue')">
            <template #default="scope">
              <div class="inline-flex" v-html="formatCurrency(scope.row.net_revenue)">

              </div>
            </template>
          </el-table-column>

          <template v-if="hasComparison">
            <el-table-column :label="translate('Compare Revenue')">
              <template #default="scope">
                <div class="inline-flex" v-html="formatCurrency(scope.row.previous_revenue)">

                </div>
              </template>
            </el-table-column>

            <el-table-column :label="translate('Change') + ' (%)'">
              <template #default="scope">
                <span :class="getPercentageClass(scope.row.percent_change)">
                  {{ scope.row.percent_change }}
                </span>
              </template>
            </el-table-column>
        </template>
        </el-table>
      </CardBody>
    </CardContainer>
  </div>
</template>

<script>
import {Container as CardContainer, Header as CardHeader, Body as CardBody} from '@/Bits/Components/Card/Card.js';
import { formatNumber } from "../Utils/formatNumber";
import { monthNames } from "./../Utils/monthNames";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from '@/utils/support/CurrencyFormatter';

export default {
  name: "Comparison",
  props: {
    currentMetrics: {
      type: Array,
    },
    previousMetrics: {
      type: Array,
    },
    reportFilter: {
      type: Object
    },
    hasComparison: {
      type: Boolean
    },
    loading: {
      type: Boolean,
      default: true,
    },
  },
  components: {
    CardContainer,
    CardHeader,
    CardBody,
  },
  data() {
    return {}
  },
  computed: {
    currencySign(){
      return this.reportFilter.currentCurrencySign;
    },

    comparisonData() {
      const comparisonData = [];

      this.currentMetrics.forEach((period, index) => {
        const currentRevenue = parseFloat(period.net_revenue);

        let item = {
          group: this.formatDate(period.group),
          net_revenue: currentRevenue,
        }

        if (this.hasComparison) {
          const previousPeriod = this.previousMetrics[index];
          if(previousPeriod){
            const previousRevenue = parseFloat(previousPeriod.net_revenue);
            const percentChange = previousRevenue ? (((currentRevenue - previousRevenue) / previousRevenue) * 100).toFixed(2) : 'n/a';

            item.previous_revenue = previousRevenue;
            item.percent_change = percentChange;
            item.group = `${this.formatDate(period.group)} vs ${this.formatDate(previousPeriod.group)}`
          }


        }
        
        comparisonData.push(item);
      });

      return comparisonData;
    }
  },
  methods: {
    translate,
    getPercentageClass(percentage) {
      if (percentage > 0) {
        return 'text-success-500';
      } else if (percentage < 0) {
        return 'text-red-500';
      } else {
        return 'text-system-light';
      }
    },
    formatDate(attr) {
      const parts = attr.split("-");
      if (parts.length === 3) {
        const [year, month, day] = parts;
        return `${monthNames[parseInt(month) - 1]} ${day}, ${year}`;
      } else if (parts.length === 2) {
        const [year, month] = parts;
        return `${monthNames[parseInt(month) - 1]} ${year}`;
      } else if (parts.length === 1) {
        const [year] = parts;
        return `${year}`;
      }
    },
    formatCurrency(value) {
      return CurrencyFormatter.scaled(value);
    }
  },
}
</script>

