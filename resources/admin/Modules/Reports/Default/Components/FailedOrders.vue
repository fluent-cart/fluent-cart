<script setup>
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import { ref, computed } from "vue";
import defaultReport from "@/Models/Reports/DefaultReportModel";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import Empty from "@/Bits/Components/Table/Empty.vue";
import ReportCard from "@/Modules/Reports/Components/ReportCard.vue";
import dayjs from "dayjs";
import translate from "@/utils/translator/Translator";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";

const props = defineProps({
  defaultReport: {
    type: [Array, Object],
    required: true,
  },
});

const reportData = defaultReport.data;

const currencySign = computed(() => {
  return reportFilter.currentCurrencySign;
});

const formatDate = (date) => {
  return dayjs(date).format("MMM D, YYYY");
};
</script>

<template>
  <UserCan :permission="'reports/view'">
    <ReportCard
      class="failed-orders"
      :card-options="{
        heading: translate('Failed Orders'),
        linkButtonText: translate('View All'),
        linkButtonUrl: 'orders',
        headingIcon: true,
      }"
    >
      <ul
        v-if="reportData?.failedOrders?.length > 0"
        class="fct-report-failed-order-list has-scroll"
      >
        <li v-for="order in reportData.failedOrders" :key="order.order_id">
          <router-link
            :to="{
              name: 'view_order',
              params: { order_id: order.order_id },
            }"
          >
            <div class="list-left-content">
              <div>
                <div class="list-title">
                  {{ order.customer_name }}
                  <span class="list-id">#{{ order.order_id }}</span>
                </div>
                <span class="list-text">{{ formatDate(order.created_a) }}</span>
              </div>
            </div>
            <div class="list-right-content">
              <div class="list-value">
                <div class="inline-flex">
                  <span v-html="currencySign" />{{ formatNumber(order.subtotal) }}
                </div>
              </div>
              <div class="list-text">
                {{ order.order_items_count }} {{ $t("Items") }}
              </div>
            </div>
          </router-link>
        </li>
      </ul>

      <Empty
        v-else
        icon="Empty/ListView"
        :has-dark="true"
        :text="$t('Currently there is no failed order!')"
      />
    </ReportCard>
  </UserCan>
</template>
