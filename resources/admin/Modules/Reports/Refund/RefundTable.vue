<template>
  <Card.Container class="overflow-hidden">
    <Card.Header :title="translate('Refunds Grouped By')" title_size="small">
      <template #action v-if="!loading">
        <div class="fct-btn-group sm w-[240px] gap-2">
          <el-select v-model="selectedGroupKey" @change="groupData" class="el-select--x-small" :placeholder="translate('Select Group')">
            <el-option v-for="group in groupKeys" :key="group.value" :label="translate(group.label)" :value="group.value" />
          </el-select>
          <ExportReport :data="tableData" filename="refund.csv" class="!ml-0" />
        </div>
      </template>
    </Card.Header>
    <Card.Body class="px-0 pb-0">
      <el-skeleton v-if="loading" animated :rows="7" class="p-4" />
      
      <el-table v-else :data="tableData" height="330">
        <el-table-column :prop="selectedGroupKey" :label="translate(selectedGroupLabel)" min-width="150">
          <template #default="scope">
            <span v-if="selectedGroupKey !== 'payment_method'"> {{ toCountryName(scope.row[selectedGroupKey]) }} </span>
            <span v-else> {{ paymentMethodAdminTitle(scope.row.payment_method) }} </span>
          </template>
        </el-table-column>

        <el-table-column prop="refunds" :label="translate('Refunds')" min-width="150" sortable :sort-method="byNumber((row) => row.totalRefunded)">
          <template #default="scope">
            {{ translateNumber(scope.row.totalRefunded) }}
          </template>
        </el-table-column>

        <el-table-column prop="refunded_amount" :label="translate('Refunded Amount')" min-width="150" sortable :sort-method="byNumber((row) => row.totalRefundedAmount.total)">
          <template #default="scope">
            <div class="inline-flex">
              {{ reportFilter.formatAmount(scope.row.totalRefundedAmount.total) }}
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="average_refund" :label="translate('Average Refund')" min-width="150" sortable :sort-method="byNumber((row) => row.totalRefundedAmount.average)">
          <template #default="scope">
            <div class="inline-flex">
              {{ reportFilter.formatAmount(scope.row.totalRefundedAmount.average) }}
            </div>
          </template>
        </el-table-column>

        <template #empty>
          <Empty icon="Empty/ListView" :has-dark="true" :text="translate('Currently there is no data!')"/>
        </template>
      </el-table>
    </Card.Body>
  </Card.Container>
</template>

<script setup>
import { ref, computed } from "vue";
import {paymentMethodAdminTitle} from "@/utils/paymentMethodAdminTitle";
import * as Card from "@/Bits/Components/Card/Card.js";
import ExportReport from "../ExportReport.vue";
import translate, { translateNumber } from "@/utils/translator/Translator";
import toCountryName from "../Utils/toCountryName";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import Empty from '@/Bits/Components/Table/Empty.vue';

const props = defineProps({
  tableData: {
    type: Array,
    required: true,
  },
  currencySign: {
    type: String,
  },
  loading: {
    type: Boolean,
    default: true,
  }
});

const emit = defineEmits(["group-data"]);

const dateRange = ref([]);
const groupKeys = ref([
  { label: translate("Billing Country"), value: "billing_country" },
  { label: translate("Shipping Country"), value: "shipping_country" },
  { label: translate("Payment Method"), value: "payment_method" },
]);
const selectedGroupKey = ref("billing_country");

const selectedGroupLabel = computed(() => {
  return groupKeys.value.find((group) => group.value === selectedGroupKey.value)?.label || "";
});

const groupData = () => {
  emit("group-data", selectedGroupKey.value);
};

const byNumber = (read) => (a, b) => Number(read(a)) - Number(read(b));
</script>
