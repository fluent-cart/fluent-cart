<script setup>
import {onMounted, onUnmounted, ref} from "vue";
import dayjs from "dayjs";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import * as Card from "@/Bits/Components/Card/Card.js";
import Empty from "@/Bits/Components/Table/Empty.vue";
import ReportLoadError from "../Components/ReportLoadError.vue";
import {formatNumber} from "../Utils/formatNumber";
import {fluentDayjsLocale, resolveDateFormat} from "@/utils/dateFormats";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const insights = ref(null);
const loading = ref(true);
// A failed refresh hides the rows: they belong to the previous filters
const failed = ref(false);
let requestSeq = 0;
let lastFilters = null;

const fetchData = (filters) => {
  const seq = ++requestSeq;
  lastFilters = filters;
  loading.value = true;
  failed.value = false;
  Rest.get("reports/customer-insights", {params: filters.params})
    .then((response) => seq === requestSeq && (insights.value = response))
    .catch((error) => seq === requestSeq && (failed.value = true, Notify.error(error)))
    .finally(() => seq === requestSeq && (loading.value = false));
};
const retry = () => fetchData(lastFilters);

onMounted(() => {
  props.reportFilter.addListener("top-customers", fetchData);
  fetchData(props.reportFilter.applicableFilters);
});

onUnmounted(() => {
  props.reportFilter.removeListener("top-customers");
  requestSeq++;
});

const money = (value) => props.reportFilter.formatAmount(value || 0);
const formatDate = (date) => dayjs(date).locale(fluentDayjsLocale()).format(resolveDateFormat("date"));
</script>

<template>
    <Card.Container class="overflow-hidden">
      <Card.Header :title="translate('Top Customers')" title_size="small" border_bottom />
      <Card.Body class="px-0 pb-0">
        <fct-skeleton v-if="loading" class="px-5 pb-5" :rows="5" animated />
        <ReportLoadError v-else-if="failed" @retry="retry" />
        <fct-table v-else :data="insights?.top_customers || []" class="w-full" max-height="520">
          <fct-table-column :label="translate('Customer')" min-width="220">
            <template #default="scope">
              <router-link :to="{ name: 'view_customer', params: { customer_id: scope.row.customer_id } }" class="font-medium">
                {{ scope.row.name || scope.row.email }}
              </router-link>
              <div v-if="scope.row.name" class="text-xs text-system-light dark:text-gray-300">{{ scope.row.email }}</div>
            </template>
          </fct-table-column>
          <fct-table-column :label="translate('Orders')" min-width="90">
            <template #default="scope">{{ formatNumber(scope.row.orders) }}</template>
          </fct-table-column>
          <fct-table-column :label="translate('Renewals')" min-width="100">
            <template #default="scope">{{ formatNumber(scope.row.renewals) }}</template>
          </fct-table-column>
          <fct-table-column :label="translate('Net in Period')" min-width="130">
            <template #default="scope">{{ money(scope.row.range_net) }}</template>
          </fct-table-column>
          <fct-table-column :label="translate('Lifetime Value')" min-width="130">
            <template #default="scope">{{ money(scope.row.lifetime_net) }}</template>
          </fct-table-column>
          <fct-table-column :label="translate('First Order')" min-width="130">
            <template #default="scope">{{ formatDate(scope.row.first_order_at) }}</template>
          </fct-table-column>
          <fct-table-column :label="translate('Last Order')" min-width="130">
            <template #default="scope">{{ formatDate(scope.row.last_order_at) }}</template>
          </fct-table-column>
          <template #empty>
            <Empty icon="Empty/ListView" has-dark :text="translate('No customers in this period')" />
          </template>
        </fct-table>
      </Card.Body>
    </Card.Container>
</template>
