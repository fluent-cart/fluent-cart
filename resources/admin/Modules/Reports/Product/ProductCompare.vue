<script setup>
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";
import translate from "@/utils/translator/Translator";

defineProps({
  reportFilter: {type: Object, required: true},
});
</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-product-compare-page">
      <PageHeading><template #title><h2 class="page-title">{{ translate('Compare products') }}</h2></template></PageHeading>
      <p class="text-sm text-system-mid dark:text-gray-300 -mt-3 mb-5">
        {{ translate('Pick whole products or single variations and see how they perform side by side.') }}
      </p>

      <ExtensionComponent name="reports.products.compare" :report-filter="reportFilter">
        <ProReportPreview
            :title="translate('Compare products side by side')"
            :text="translate('ProductRpt_Put_Six')"
            :bullets="[translate('Net sales, orders and refunds over time'), translate('Sales since each product launched'), translate('Customers who bought both')]"
            placement="feature_lock_report_product_compare"
        >
          <ProReportSample
              :title="translate('Compare products')"
              :columns="[translate('Product'), translate('Net sales'), translate('Orders'), translate('Refund rate')]"
              :rows="[[translate('Product A'), '5,000.00', '100', '2%'], [translate('Product B'), '3,000.00', '60', '4%']]"
          />
        </ProReportPreview>
      </ExtensionComponent>
    </div>
  </UserCan>
</template>
