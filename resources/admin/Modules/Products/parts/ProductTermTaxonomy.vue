<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import TermInput from "@/Bits/Components/Inputs/TermInput.vue";

const props = defineProps({
  product: Object,
  productEditModel: Object,
})
</script>

<template>
  <div class="fct-product-simple-wrap">
    <Card.Container>
      <Card.Header :title="$t('Product Taxonomies')" border_bottom title_size="small"></Card.Header>
      <Card.Body>
<!--        <div class="fct-admin-summary-item" v-if="product.detail">-->
<!--          <el-radio-group class="fct-radios-blocks" v-model="product.detail.fulfillment_type" @change="value => {updateProductValue('fulfillment_type',value)}">-->
<!--            <el-radio value="physical" border dashed>{{$t('Physical Product')}}</el-radio>-->
<!--            <el-radio value="digital" border dashed>{{$t('Digital Product')}}</el-radio>-->
<!--          </el-radio-group>-->
<!--        </div>&lt;!&ndash; .fct-admin-summary-item &ndash;&gt;-->

        <div class="fct-admin-summary-item" v-for="taxonomy of product.taxonomies" :key="taxonomy.id" >
          <h4>{{taxonomy.labels.name}}</h4>
          <TermInput :product_id="product.ID" :taxonomy="taxonomy" v-model="product.product_terms[taxonomy.name]"/>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
