<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import {paymentMethodAdminTitle} from "@/utils/paymentMethodAdminTitle";
import orderReportModel from "@/Models/Reports/OrderReportModel";
import ExportReport from "../ExportReport.vue";
import { computed } from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import translateNumber from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import toCountryName from "../Utils/toCountryName";
import Empty from '@/Bits/Components/Table/Empty.vue';

const props = defineProps({
  reportFilter: {
    type: Object,
  },
});

const reportFilter = props.reportFilter;
const selectedGroupKey = ref("billing_country");

const currencySign = computed(() => {
  return reportFilter.currentCurrencySign;
});

const getData = (filters = reportFilter.applicableFilters) => {
  orderReportModel.getOrderByGroup({
    params: {
      ...filters.params,
      groupKey: selectedGroupKey.value,
    },
  });
};

const groupKeys = [
  { label: translate("Billing Country"), value: "billing_country" },
  { label: translate("Shipping Country"), value: "shipping_country" },
  { label: translate("Payment Method"), value: "payment_method" },
];

const byNumber = (key) => (a, b) => Number(a[key]) - Number(b[key]);

const formatPercentage = (value) => {
  return `${parseFloat(value).toFixed(2)}%`;
};

onMounted(() => {
  reportFilter.addListener("order-grouped-by", getData);
  getData();
});

onUnmounted(() => {
  reportFilter.removeListener("order-grouped-by");
});
</script>

<template>
  <Card.Container class="overflow-hidden" id="chartContainer">
    <Card.Header :title="translate('Orders grouped by')" title_size="small">
      <template #action>
        <div class="w-[240px] fct-btn-group gap-1.5">
          <el-select
            class="el-select--x-small"
            v-model="selectedGroupKey"
            @change="getData()"
            :placeholder="translate('Select Group')"
          >
            <el-option
              v-for="group in groupKeys"
              :key="group.value"
              :label="group.label"
              :value="group.value"
            />
          </el-select>
          <ExportReport
            :data="orderReportModel.data.orderByGroup"
            :filename="'order_grouped_by.csv'"
            class="m-0"
          />
        </div>
      </template>
    </Card.Header>
    <Card.Body class="px-0 pb-0">
      <el-table
        :data="orderReportModel.data.orderByGroup"
        border
        class="max-h-[350px] overflow-y-auto"
      >
        <el-table-column
          prop="payment_method"
          :label="translate('Payment Method')"
          min-width="150"
          v-if="selectedGroupKey === 'payment_method'"
        >
          <template #default="scope">
            {{ paymentMethodAdminTitle(scope.row.payment_method) }}
          </template>
        </el-table-column>

        <el-table-column
          prop="shipping_country"
          :label="translate('Country')"
          min-width="150"
          v-if="selectedGroupKey === 'shipping_country'"
        >
          <template #default="scope">
            <span class="capitalize">{{ toCountryName(scope.row.shipping_country) }}</span>
          </template>
        </el-table-column>

        <el-table-column
          prop="billing_country"
          :label="translate('Billing Country')"
          min-width="150"
          v-if="selectedGroupKey === 'billing_country'"
        >
          <template #default="scope">
            <span class="capitalize">{{ toCountryName(scope.row.billing_country) }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="orders" :label="translate('Orders')" min-width="150" sortable :sort-method="byNumber('orders')">
          <template #default="scope">
            {{ translateNumber(scope.row.orders) }}
          </template>
        </el-table-column>

        <el-table-column prop="gross_sale" :label="translate('Gross Sales')" min-width="150" sortable :sort-method="byNumber('gross_sale')">
          <template #default="scope">
            <div class="inline-flex" v-html="reportFilter.formatAmount(scope.row.gross_sale)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="average_order_gross"
          :label="translate('Average Gross Sales')"
          min-width="150"
          sortable
          :sort-method="byNumber('average_order_gross')"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="reportFilter.formatAmount(scope.row.average_order_gross)">

            </div>
          </template>
        </el-table-column>

        <el-table-column prop="net_sale" :label="translate('Revenue')" min-width="150" sortable :sort-method="byNumber('net_sale')">
          <template #default="scope">
            <div class="inline-flex" v-html="reportFilter.formatAmount(scope.row.net_sale)">

            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="average_order_net"
          :label="translate('Average Revenue')"
          min-width="150"
          sortable
          :sort-method="byNumber('average_order_net')"
        >
          <template #default="scope">
            <div class="inline-flex" v-html="reportFilter.formatAmount(scope.row.average_order_net)">
              
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
