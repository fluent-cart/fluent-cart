<script setup>
import { ref, shallowRef, onMounted, nextTick, computed, watch } from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import ExportReport from "../ExportReport.vue";
import toCountryName from "../Utils/toCountryName";
import ReportTableLoader from "../ReportTableLoader.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import { liabilityClass, maybeLiability } from "../Utils/liability";
import translateNumber from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  tableData: {
    type: Object,
  },
  reportFilter: {
    type: Object,
  },
  revenueReport: {
    type: Object,
  },
});

const emit = defineEmits(["fetch-data-by-group"]);

const currencySign = computed(() => {
  return props.reportFilter.currentCurrencySign;
});

const groupKeys = [
  { label: "Billing Country", value: "billing_country" },
  { label: "Shipping Country", value: "shipping_country" },
  { label: "Payment Method", value: "payment_method_title" },
];

const paymentMethodMap = ref({
  offline_payment: "Cash On Delivery",
  paypal: "PayPal",
  stripe: "Stripe",
});

const selectedGroupKey = ref("billing_country");

const selectGroupKey = () => {
  emit("fetch-data-by-group", selectedGroupKey.value);
};

const getPaymentMethodLabel = (paymentMethod) => {
  return paymentMethodMap.value[paymentMethod] || paymentMethod.value;
};
</script>

<template>
  <Card.Container class="overflow-hidden">
    <Card.Header :title="$t('Grouped by')" title_size="small">
      <template #action>
        <div class="fct-btn-group sm w-[240px] gap-2">
          <el-select
            class="el-select--x-small"
            v-model="selectedGroupKey"
            @change="selectGroupKey"
            :placeholder="$t('Select Group')"
          >
            <el-option
              v-for="group in groupKeys"
              :key="group.value"
              :label="$t(group.label)"
              :value="group.value"
            />
          </el-select>
          <export-report
            :data="tableData"
            :filename="'revenue grouped by.csv'"
            class="!ml-0"
          />
        </div>
      </template>
    </Card.Header>
    <Card.Body class="px-0 pb-0">
      <ReportTableLoader :loading="revenueReport.data.isBusy.netRevenueByGroup" />

      <el-table v-if="!revenueReport.data.isBusy.netRevenueByGroup" :data="tableData" max-height="390">
        <el-table-column
          prop="country"
          :label="$t('Country')"
          min-width="150"
          v-if="selectedGroupKey === 'country'"
        >
          <template #default="scope">
            {{ scope.row.country }}
          </template>
        </el-table-column>

        <el-table-column
          prop="country"
          :label="$t('Country')"
          min-width="150"
          v-if="selectedGroupKey === 'shipping_country'"
        >
          <template #default="scope">
            <span class="capitalize">{{ toCountryName(scope.row.shipping_country) }}</span>
          </template>
        </el-table-column>

        <el-table-column
          prop="country"
          :label="$t('Country')"
          min-width="150"
          v-if="selectedGroupKey === 'billing_country'"
        >
          <template #default="scope">
            <span class="capitalize">{{ toCountryName(scope.row.billing_country) }}</span>
          </template>
        </el-table-column>

        <el-table-column
          prop="payment_method"
          :label="$t('Payment Method')"
          min-width="150"
          v-if="selectedGroupKey === 'payment_method_title'"
        >
          <template #default="scope">
            {{ scope.row.payment_method }}
          </template>
        </el-table-column>
        <el-table-column
          prop="order_count"
          :label="$t('Order Count')"
          min-width="150"
        >
          <template #default="scope">
            {{ translateNumber(scope.row.orders) }}
          </template>
        </el-table-column>

        <el-table-column
          prop="total_sales"
          :label="$t('Gross Sales')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex">
              {{ CurrencyFormatter.scaled(scope.row.gross_sale) }}
            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="total_refunds"
          :label="$t('Refunds')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex" :class="liabilityClass(scope.row.total_refunds)">
              {{ maybeLiability(scope.row.total_refunds) }}
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="total_tax" :label="$t('Taxes')" min-width="150" v-if="total_tax > 0">
          <template #default="scope">
            <div class="inline-flex" :class="liabilityClass(scope.row.total_tax)">
              {{ maybeLiability(scope.row.total_tax) }}
            </div>
          </template>
        </el-table-column>

        <el-table-column
          prop="shipping_total"
          :label="$t('Shipping')"
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
          :label="$t('Revenue')"
          min-width="150"
        >
          <template #default="scope">
            <div class="inline-flex">
              {{ CurrencyFormatter.scaled(scope.row.net_sale) }}
            </div>
          </template>
        </el-table-column>

        <template #empty>
          <el-empty :text="$t('No data available')" />
        </template>
      </el-table>
    </Card.Body>
  </Card.Container>
</template>
