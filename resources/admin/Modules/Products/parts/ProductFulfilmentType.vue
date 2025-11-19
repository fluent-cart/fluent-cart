<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';

const props = defineProps({
  product: Object,
  productEditModel: Object,
})
</script>

<template>
  <div class="fct-product-simple-wrap">
    <Card.Container>
     <Card.Header :title="$t('Type')" border_bottom title_size="small"></Card.Header>
     <Card.Body>
       <div class="fct-admin-summary-item" v-if="product.detail">
         <el-radio-group class="fct-radios-blocks" v-model="product.detail.fulfillment_type" @change="value => {productEditModel.onChangeInputField('fulfillment_type',value)}">
           <el-radio value="physical" border>{{$t('Physical Product')}}</el-radio>
           <el-radio value="digital" border>{{$t('Digital Product')}}</el-radio>
         </el-radio-group>
       </div><!-- .fct-admin-summary-item -->
      </Card.Body>
    </Card.Container>
  </div>
</template>
