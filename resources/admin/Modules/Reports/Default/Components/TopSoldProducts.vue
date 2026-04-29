<script setup>
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import { ref, computed } from "vue";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import Empty from "@/Bits/Components/Table/Empty.vue";
import ReportCard from "@/Modules/Reports/Components/ReportCard.vue";
import dayjs from "dayjs";
import translate from "@/utils/translator/Translator";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import {translateNumber} from "@/utils/translator/Translator";
import defaultReport from "@/Models/Reports/DefaultReportModel";

const props = defineProps({
  data: {
    type: Array,
    required: true,
  },
  loading: {
    type: Boolean,
    required: true,
  },
});

const reportData = computed(() => props.data);

const formatDate = (date) => {
  return dayjs(date).format("MMM D, YYYY");
};
</script>

<template>
  <UserCan :permission="'reports/view'">
    <ReportCard
      class="top-sold-products"
      :card-options="{
        heading: translate('Top Sold Products'),
        linkButtonText: translate('View All'),
        linkButtonUrl: 'products',
      }"
    >
    <el-skeleton v-if="loading" animated :rows="7" />

    <template v-else>

      <ul
        v-if="reportData.length > 0"
        class="fct-report-sold-product-list has-scroll"
      >
        <li
          v-for="product in reportData"
          :key="product.product_id"
        >
          <router-link
            :to="{
              name: 'product_edit',
              params: { product_id: product.product_id },
            }"
          >
            <div class="list-left-content">
              <div class="list-image">
                <img :src="product.media" v-if="product.media !== null" alt=""/>
                <img :src="getPlaceholderImage()" alt="" v-else />
              </div>
              <div class="list-title">
                <div class="list-title-inner" :title="product.product_name">
                  {{ product.product_name }}
                </div>
                <div class="list-id">#{{ translateNumber(product.product_id) }}</div>
              </div>
            </div>
            <div class="list-right-content">
              <div class="list-value">
                {{ translateNumber(product.quantity_sold) }} {{ translate(" Sold") }}
              </div>
              <div class="list-price" v-html="CurrencyFormatter.scaled(product.total_amount)">

              </div>
            </div>
          </router-link>
        </li>
      </ul>

      <Empty
        v-else
        icon="Empty/ListView"
        :has-dark="true"
        :text="translate('Currently there is no item!')"
      />

    </template>
    </ReportCard>
  </UserCan>
</template>
