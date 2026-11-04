<script setup>
import {computed} from "vue";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";
import translate from "@/utils/translator/Translator";

// The product drawer without Pro: the same drawer, with a locked preview inside
const props = defineProps({
  product: {type: Object, default: null},
});
const emit = defineEmits(['close']);
const open = computed({
  get: () => !!props.product,
  set: (value) => !value && emit('close'),
});
</script>

<template>
  <el-drawer v-model="open" size="760px" :title="product ? product.product_title : ''" class="fct-product-detail-drawer">
    <ProReportPreview
        :title="translate('See what drives each product')"
        :text="translate('ProductRpt_Open_Product')"
        :bullets="[translate('Sales and refunds by variation'), translate('Subscription renewals'), translate('Bought together')]"
        placement="feature_lock_report_product_detail"
    >
      <ProReportSample
          :title="translate('Bought together')"
          :columns="[translate('Product'), translate('Orders'), translate('Share')]"
          :rows="[[translate('Product A'), '40', '40%'], [translate('Product B'), '20', '20%']]"
      />
    </ProReportPreview>
  </el-drawer>
</template>
