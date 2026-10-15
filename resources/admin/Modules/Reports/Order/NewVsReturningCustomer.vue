<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import ExportReport from "../ExportReport.vue";
import { computed, onMounted, ref, watch } from "vue";
import { formatNumber } from "../Utils/formatNumber";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import orderReport from "@/Models/Reports/OrderReportModel";

const props = defineProps({
  tableData: {
    type: Object,
    required: true,
  },
  reportFilter: {
    type: Object,
  },
});

const groupKeys = [
  { label: "Year", value: "year" },
  { label: "Month", value: "month" },
  { label: "Day", value: "day" },
];
const selectedGroupKey = ref("number");
const isCheckedPercentage = ref(false);

const customerTypeLabels = {
  New: translate("New"),
  Returning: translate("Returning"),
  Renewal: translate("Renewal"),
  Guest: translate("Guest"),
};

const customerTypeLabel = (type) => {
  return customerTypeLabels[type] ?? type;
};

const dataLoader = computed(() => {
  return orderReport.data.isBusy.newVsReturning;
});

const dataSource = computed(() => {
  if (isCheckedPercentage.value === true) {
    const metrics = [
      'customer_count',
      'order_count',
      'net_sales',
      'average_net',
      'gross_sales',
      'average_gross',
    ];

    const rows = props.tableData || [];
    const totals = {};

    metrics.forEach((key) => {
      totals[key] = rows.reduce((sum, row) => sum + (parseFloat(row[key]) || 0), 0);
    });

    return rows.map((row) => {
      const result = { customer_type: row.customer_type };

      metrics.forEach((key) => {
        const value = parseFloat(row[key]) || 0;
        result[key] = totals[key] ? (value * 100) / totals[key] : 0;
      });

      return result;
    });
  }

  return props.tableData;
});

const currencySign = computed(() => {
  return props.reportFilter.currentCurrencySign;
});

onMounted(() => {});

const formatPercentage = (value) => {
  return `${parseFloat(value).toFixed(2)}%`;
};
</script>

<template>
  <Card.Container class="overflow-hidden" id="chartContainer">
    <Card.Header title_size="small">
      <template #title>
        <div class="flex items-center gap-2">
          <h5 class="fct-card-header-title is-small">{{
            translate("New vs. Returning Customers")
          }}</h5>
          <el-tooltip
            popper-class="fct-tooltip"
            :content="
              translate(
                'New: the order is the customer\'s first paid order. Returning: the customer had an earlier paid order. Renewal: subscription renewal orders. Guest: orders without a customer account.'
              )
            "
            placement="top"
          >
            <DynamicIcon name="InformationFill" class="text-gray-500 w-4 h-4 cursor-pointer" />
          </el-tooltip>
        </div>
      </template>
      <template #action>
        <div class="fct-btn-group sm">
          <el-checkbox v-model="isCheckedPercentage">
            {{ translate("Show Percentages") }}
          </el-checkbox>
          <ExportReport :data="dataSource" :filename="'order_report.csv'" />
        </div>
      </template>
    </Card.Header>
    <Card.Body class="px-0 pb-0">
      <el-skeleton v-if="dataLoader" animated :rows="3" class="p-3" />

      <el-table v-else :data="dataSource" border>
        <el-table-column prop="customer_type" :label="translate('Type')" min-width="90">
          <template #default="scope">
            {{ customerTypeLabel(scope.row.customer_type) }}
          </template>
        </el-table-column>

        <el-table-column
          prop="customer_count"
          :label="translate('Customer Count')"
          min-width="120"
        >
          <template #default="scope">
            {{
              isCheckedPercentage
                ? formatPercentage(scope.row.customer_count)
                : scope.row.customer_count
            }}
          </template>
        </el-table-column>

        <el-table-column
          prop="order_count"
          :label="translate('Order Count')"
          min-width="150"
        >
          <template #default="scope">
            {{
              isCheckedPercentage
                ? formatPercentage(scope.row.order_count)
                : scope.row.order_count
            }}
          </template>
        </el-table-column>

        <el-table-column
          prop="gross_sales"
          :label="translate('Gross Sales')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="isCheckedPercentage
                  ? formatPercentage(scope.row.gross_sales)
                  : reportFilter.formatAmount(scope.row.gross_sales)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="average_gross"
          :label="translate('Average Gross Sales')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="isCheckedPercentage ? formatPercentage(scope.row.average_gross) : reportFilter.formatAmount(scope.row.average_gross)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="net_sales"
          :label="translate('Revenue')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="isCheckedPercentage
                  ? formatPercentage(scope.row.net_sales)
                  : reportFilter.formatAmount(scope.row.net_sales)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="average_net"
          :label="translate('Average Revenue')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="isCheckedPercentage ? formatPercentage(scope.row.average_net) : reportFilter.formatAmount(scope.row.average_net)">

            </div>
          </template>
        </el-table-column>

        <template #empty>
          <el-empty :text="translate('No data available')" />
        </template>
      </el-table>
    </Card.Body>
  </Card.Container>
</template>

<style scoped>
.el-table th,
.el-table td {
  text-align: center;
}
</style>
