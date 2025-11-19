<script setup>
import { computed } from "vue";
import defaultReport from "@/Models/Reports/DefaultReportModel";
import Empty from "@/Bits/Components/Table/Empty.vue";
import ReportCard from "@/Modules/Reports/Components/ReportCard.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  defaultReport: {
    type: [Array, Object],
    required: true,
  },
});

const reportData = defaultReport.data;

const dataLoader = computed(() => {
  return defaultReport.data.isBusy.topSoldVariants;
});

</script>

<template>
  <UserCan :permission="'reports/view'">
    <ReportCard
      class="top-sold-variants"
      :card-options="{
        heading: translate('Top Sold Variants'),
      }"
    >
      <el-skeleton v-if="dataLoader" animated :rows="7" />

      <template v-else>
        <ul
          v-if="reportData?.topSoldVariants?.length > 0"
          class="fct-report-sold-product-list has-scroll"
        >
          <li
            v-for="product in reportData.topSoldVariants"
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
                  <img
                    :src="product.media_url"
                    :alt="product.media?.title"
                    v-if="product.media_url !== null"
                  />
                  <img :src="getPlaceholderImage()" alt="" v-else />
                </div>
                <div class="list-title">
                  <div class="list-title-inner">{{ product.variation_name }}</div>
                  <div class="list-id">
                    <strong>{{ product.product_name }}</strong>
                  </div>
                </div>
              </div>
              <div class="list-right-content">
                <div class="list-value">
                  {{ product.quantity }} {{ translate(" Sold") }}
                </div>
                <div class="list-price">
                  <div class="inline-flex">
                    {{ CurrencyFormatter.scaled(product.total_amount) }}
                  </div>
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
