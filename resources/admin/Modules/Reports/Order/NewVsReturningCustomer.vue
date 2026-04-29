<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import ExportReport from "../ExportReport.vue";
import { computed, onMounted, ref, watch } from "vue";
import { formatNumber } from "../Utils/formatNumber";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
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

// const emit = defineEmits(["fetch-new-vs-returning-customer"]);

// const selectGroupKey = () => {
  // if (isCheckedPercentage.value === true) {
  //   selectedGroupKey.value = "percentage";
  // } else {
  //   selectedGroupKey.value = "number";
  // }
  // emit("fetch-new-vs-returning-customer", selectedGroupKey.value);
// };

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

    const newResult = { customer_type: "New" };
    const returningResult = { customer_type: "Returning" };

    metrics.forEach((key) => {
      const newValue = parseFloat(props.tableData[0][key]);
      const returningValue = parseFloat(props.tableData[1][key]);
      const total = newValue + returningValue;
      
      newResult[key] = (newValue * 100) / total;
      returningResult[key] = (returningValue * 100) / total;
    })

    return [newResult, returningResult];
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

<style scoped>
.el-table th,
.el-table td {
  text-align: center;
}
</style>

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
                'Returning customers are those who first ordered before this period.'
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
          <el-checkbox v-model="isCheckedPercentage" @change="selectGroupKey">
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
            {{ scope.row.customer_type }}
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
                  : CurrencyFormatter.scaled(scope.row.gross_sales)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="average_gross"
          :label="translate('Average Gross Sales')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="isCheckedPercentage ? formatPercentage(scope.row.average_gross) : CurrencyFormatter.scaled(scope.row.average_gross)">

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
                  : CurrencyFormatter.scaled(scope.row.net_sales)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="average_net"
          :label="translate('Average Revenue')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="isCheckedPercentage ? formatPercentage(scope.row.average_net) : CurrencyFormatter.scaled(scope.row.average_net)">

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
