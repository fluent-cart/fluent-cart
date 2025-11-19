<script setup>
import dashBoardReport from "@/Models/Reports/DashBoardReportModel.js";
import Empty from "@/Bits/Components/Table/Empty.vue";
import ReportCard from "@/Modules/Reports/Components/ReportCard.vue";
import translate, {translateNumber} from "@/utils/translator/Translator";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

const reportData = dashBoardReport.data;
</script>

<template>
  <ReportCard
    class="top-sold-products"
    :card-options="{
      heading: translate('Recent Orders'),
      linkButtonText: translate('View All'),
      linkButtonUrl: 'orders',
    }"
  >
    <ul
      v-if="reportData?.recentOrders?.length > 0"
      class="fct-report-sold-product-list has-scroll"
    >
      <li v-for="order in reportData.recentOrders" :key="order.id">
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
                {{ order.customer_name }} #{{ translateNumber(order.id) }}
              </div>
              <div class="list-id"><ConvertedTime :date-time="order.created_at"/></div>
            </div>
          </div>
          <div class="list-right-content">
            <div class="list-value">
              {{ CurrencyFormatter.scaled(order.total_amount) }}
            </div>
            <div class="list-price">
              {{ translateNumber(order.order_items_count) }} {{ translate("Items") }}
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
