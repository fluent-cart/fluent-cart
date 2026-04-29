<template>
  <div class="fct-revenue-comparison">
    <CardContainer class="overflow-hidden">
      <CardHeader :title="translate('Comparison')" title_size="small" />
      <CardBody class="px-0 pb-0">
        <el-skeleton v-if="loading" animated :rows="6" class="p-5" />

        <el-table v-else :data="comparisonData" class="w-full" maxHeight="390">
          <el-table-column label="Date">
            <template #default="scope">
              {{ scope.row.group }}
            </template>
          </el-table-column>

          <el-table-column :label="translate('Current Range')">
            <template #default="scope">
              <div class="inline-flex">
                {{ scope.row.count }}
              </div>
            </template>
          </el-table-column>

          <template v-if="hasComparison">
            <el-table-column :label="translate('Compare Range')">
              <template #default="scope">
                <div class="inline-flex">
                  {{ scope.row.previous_count }}
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
import { monthNames } from "./../Utils/monthNames";
import translate from "@/utils/translator/Translator";

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
      required: true
    }
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

      if (!this.hasComparison) {
        return this.currentMetrics;
      }

      this.currentMetrics.forEach((period, index) => {
        if (index < this.previousMetrics?.length) {
          const previousPeriod = this.previousMetrics[index];
          
          const currentCount = period.customer_count;
          const previousCount = previousPeriod.customer_count;
          
          const percentChange = previousCount ? (((currentCount - previousCount) / previousCount) * 100).toFixed(2) : 'n/a';
          
          comparisonData.push({
              group: `${this.formatDate(period.group)} vs ${this.formatDate(previousPeriod.group)}`,
              count: currentCount,
              previous_count: previousCount,
              percent_change: percentChange
          });
        }
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
    }
  },
}
</script>

