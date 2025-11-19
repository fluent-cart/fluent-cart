<script setup>
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import { ref, computed } from "vue";
import dashBoardReport from "@/Models/Reports/DashBoardReportModel.js";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import Empty from "@/Bits/Components/Table/Empty.vue";
import ReportCard from "@/Modules/Reports/Components/ReportCard.vue";
import dayjs from "dayjs";
import translate from "@/utils/translator/Translator";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";

const currencySign = computed(() => {
  return reportFilter.currentCurrencySign;
});

const reportData = dashBoardReport.data;

const formatDate = (date) => {
  return dayjs(date).format("MMM D, YYYY");
};
</script>

<template>
  <ReportCard
    class="top-sold-products"
    :card-options="{
      heading: translate('Unfulfilled Orders'),
      linkButtonText: translate('View All'),
      linkButtonUrl: 'orders',
    }"
  >
    <ul
      v-if="reportData?.unfulfilledOrders?.length > 0"
      class="fct-report-sold-product-list has-scroll"
    >
      <li v-for="order in reportData.unfulfilledOrders" :key="order.id">
        <router-link
            class="no-underline"
          :to="{
            name: 'view_order',
            params: { order_id: order.id },
          }"
        >
          <div class="list-left-content">
            <div class="list-title">
              <div class="list-title-inner">
                {{ order.customer_name }} #{{ order.id }}
              </div>
              <div class="list-id"><ConvertedTime :date-time="order.created_at"/></div>
            </div>
          </div>
          <div class="list-right-content">
            <div class="list-value">
              <span v-html="currencySign"></span>{{ formatNumber(order.total_amount) }}
            </div>
            <div class="list-price">
              {{ order.order_items_count }} {{ translate("Items") }}
            </div>
          </div>
        </router-link>
      </li>
    </ul>
    <Empty
      v-else
      icon="Empty/ListView"
      :text="translate('Currently there is no item!')"
    />
  </ReportCard>
</template>
