<script setup>
import {ref} from "vue";
import SingleVariation from "@/Modules/Products/BulkInsert/SingleVariation.vue";

const props = defineProps({
  product: {
    required: true,
    type: Object
  },
  pushVariationToProduct: {
    required: true,
    type: Function
  }
})

const statusOptions = [
  {
    title: $t('Published'),
    value: 'published'
  },
  {
    title: $t('Draft'),
    value: 'draft'
  }
];
const variationOptions = [
  {
    title: $t('Simple'),
    value: 'simple'
  },
  {
    title: $t('Simple Variations'),
    value: 'simple_variations'
  }
];





const isCollapsed = ref(false);
const shouldShowCollapsible = () => props.product?.detail?.variation_type !== 'simple';

const handleVariationChanged = (variation) => {
  if (variation === 'simple_variations') {
    ensureVariantsIsArray(props.product);
    if (props.product.variants.length < 1) {
      props.pushVariationToProduct({
        variation_title: props.product.post_title
      });
    }
  } else {
    props.product.variants = null;
  }
}

const ensureVariantsIsArray = () => {
  if (!Array.isArray(props.product.variants)) {
    props.product.variants = [];
  }
}
</script>

<template>


  <div class='fct-collapsible-content-wrapper flex justify-between gap-4 w-full' :style="{
                flex: '0 0 auto'
            }">


    <el-button v-if="shouldShowCollapsible()" @click="isCollapsed = !isCollapsed">
      Collapse
    </el-button>

    <el-input v-model="product.post_title" :placeholder="$t('Product Title')"/>
    <el-select v-model="product.post_status">
      <el-option
          v-for="option in statusOptions"
          :key="option.value"
          :label="$t(option.title)"
          :value="option.value"
      />
    </el-select>

    <el-select v-model="product.detail.variation_type" @change="handleVariationChanged">
      <el-option
          v-for="variation in variationOptions"
          :key="variation.value"
          :label="$t(variation.title)"
          :value="variation.value"
      />
    </el-select>


  </div>


  <div v-if="shouldShowCollapsible() && !isCollapsed" class="fct-collapsible-item-child-container w-full pl-4"
       :class="{ 'is-collapsed': !isCollapsed }">
    <table class="ml-4">
      <thead>
      <tr>
        <td>{{ $t('Title') }}</td>
        <td width="100px">{{ $t('Price') }}</td>
        <td width="100px">{{ $t('Compare Price') }}</td>
        <td width="200px">{{ $t('Payment Type') }}</td>

      </tr>
      </thead>
      <tbody>
      <SingleVariation :index="index" :variant="variant" v-for="(variant,index) in product.variants"/>
      </tbody>
    </table>
  </div>


</template>

<style scoped>

</style>
