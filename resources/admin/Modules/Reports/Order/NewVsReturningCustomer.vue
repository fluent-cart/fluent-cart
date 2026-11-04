<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import {computed, ref} from "vue";
import {formatNumber} from "../Utils/formatNumber";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Notify from "@/utils/Notify";
import {exportCSVWithColumns} from "@/utils/Export";
import orderReport from "@/Models/Reports/OrderReportModel";

const props = defineProps({
  tableData: {
    type: Array,
    required: true,
  },
  reportFilter: {
    type: Object,
  },
});

const isCheckedPercentage = ref(false);

const customerTypeLabels = {
  New: translate("New"),
  Returning: translate("Returning"),
  Renewal: translate("Renewal"),
  "Installment payment": translate("Installment payment"),
  "Free order": translate("Free order"),
  Guest: translate("Guest"),
};
const customerTypeLabel = (type) => customerTypeLabels[type] ?? type;

const dataLoader = computed(() => orderReport.data.isBusy.newVsReturning);

// Shares of the column total; an average has no meaningful share, so it stays an amount
const SHARE_KEYS = ['customer_count', 'order_count', 'net_sales'];
const totals = computed(() => {
  const sums = {};
  SHARE_KEYS.forEach((key) => {
    sums[key] = (props.tableData || []).reduce((sum, row) => sum + (parseFloat(row[key]) || 0), 0);
  });
  return sums;
});
const share = (row, key) => {
  const total = totals.value[key];
  return (total ? Math.round((parseFloat(row[key]) || 0) * 1000 / total) / 10 : 0) + '%';
};
const money = (value) => props.reportFilter.formatAmount(value || 0);

const exportCsv = () => {
  const columns = [
    {header: translate('Type'), accessor: 'customer_type', formatter: customerTypeLabel},
    ...['customer_count', 'order_count', 'net_sales', 'average_net', 'gross_sales', 'average_gross']
      .map(key => ({header: key, accessor: key})),
  ];
  exportCSVWithColumns(props.tableData || [], columns, 'new-vs-returning-customers.csv').catch(error => Notify.error(error.message));
};
</script>

<template>
  <Card.Container class="overflow-hidden" id="chartContainer">
    <Card.Header title_size="small" border_bottom>
      <template #title>
        <div class="flex items-center gap-2">
          <h5 class="fct-card-header-title is-small">{{ translate("New vs. Returning Customers") }}</h5>
          <el-tooltip
              popper-class="fct-tooltip"
              :content="translate('OrderRpt_New_Customer')"
              placement="top"
              :trigger="['hover', 'focus']"
          >
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
              <DynamicIcon name="InformationFill" class="text-gray-500 w-4 h-4" aria-hidden="true"/>
            </button>
          </el-tooltip>
        </div>
      </template>
      <template #action>
        <div class="flex items-center gap-3">
          <el-checkbox v-model="isCheckedPercentage">{{ translate("Show Percentages") }}</el-checkbox>
          <div class="fct-btn-group sm">
            <el-tooltip effect="dark" :content="translate('Export CSV')" placement="top" popper-class="fct-tooltip">
              <IconButton tag="button" :disabled="dataLoader || !(tableData || []).length" :aria-label="translate('Export CSV')" @click="exportCsv">
                <DynamicIcon name="Download"/>
              </IconButton>
            </el-tooltip>
          </div>
        </div>
      </template>
    </Card.Header>
    <Card.Body class="px-0 pb-0">
      <el-skeleton v-if="dataLoader" animated :rows="3" class="p-3"/>

      <el-table v-else :data="tableData">
        <el-table-column :label="translate('Type')" min-width="170">
          <template #default="{row}">{{ customerTypeLabel(row.customer_type) }}</template>
        </el-table-column>
        <el-table-column :label="translate('Customers')" min-width="120">
          <template #default="{row}">{{ isCheckedPercentage ? share(row, 'customer_count') : formatNumber(row.customer_count) }}</template>
        </el-table-column>
        <el-table-column :label="translate('Orders')" min-width="120">
          <template #default="{row}">{{ isCheckedPercentage ? share(row, 'order_count') : formatNumber(row.order_count) }}</template>
        </el-table-column>
        <el-table-column :label="translate('Net Sales')" min-width="140">
          <template #default="{row}">{{ isCheckedPercentage ? share(row, 'net_sales') : money(row.net_sales) }}</template>
        </el-table-column>
        <el-table-column :label="translate('Average Order (Net)')" min-width="160">
          <template #default="{row}">{{ money(row.average_net) }}</template>
        </el-table-column>

        <template #empty>
          <el-empty :description="translate('No data available')"/>
        </template>
      </el-table>
    </Card.Body>
  </Card.Container>
</template>
