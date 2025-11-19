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
            <span v-if="selectedGroupKey !== 'payment_method_type' && selectedGroupKey !== 'payment_method_title'"> {{ toCountryName(scope.row[selectedGroupKey]) }} </span>
            <span v-else> {{ scope.row[selectedGroupKey] }} </span>
          </template>
        </el-table-column>

        <el-table-column prop="refunds" :label="translate('Refunds')" min-width="150">
          <template #default="scope">
            {{ scope.row.totalRefunded }}
          </template>
        </el-table-column>

        <el-table-column prop="refunded_amount" :label="translate('Refunded Amount')" min-width="150">
          <template #default="scope">
            <div class="inline-flex">
              {{ CurrencyFormatter.scaled(scope.row.totalRefundedAmount.total) }}
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="average_refund" :label="translate('Average Refund')" min-width="150">
          <template #default="scope">
            <div class="inline-flex">
              {{ CurrencyFormatter.scaled(scope.row.totalRefundedAmount.average) }}
            </div>
          </template>
        </el-table-column>

        <template #empty>
          <el-empty text="No data available" />
        </template>
      </el-table>
    </Card.Body>
  </Card.Container>
</template>

<script setup>
import { ref, computed } from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import ExportReport from "../ExportReport.vue";
import { formatNumber } from "../Utils/formatNumber";
import dayjs from "dayjs";
import translate from "@/utils/translator/Translator";
import toCountryName from "../Utils/toCountryName";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import { isEmpty } from "@/utils/Utils";

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
  { label: translate("Payment Method Title"), value: "payment_method_title" },
]);
const selectedGroupKey = ref("billing_country");

const selectedGroupLabel = computed(() => {
  return groupKeys.value.find((group) => group.value === selectedGroupKey.value)?.label || "";
});

const groupData = () => {
  emit("group-data", selectedGroupKey.value);
};
</script>
