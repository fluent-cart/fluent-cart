<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import LabelHint from "@/Bits/Components/LabelHint.vue";
import {watch} from "vue";

const props = defineProps({
  product: Object,
  productEditModel: Object,
})

const defaultOtherInfo = {
    use_pricing_table: 'no',
    group_pricing_by: 'payment_type',
    sold_individually: 'no',
    order_note_enabled: 'no',
    order_note_title: ''
};

watch(
    () => props.product.detail,
    (newDetail) => {
        if (newDetail) {
            props.product.detail.other_info = {
              ...defaultOtherInfo,
              ...(newDetail.other_info || {})
            };
        }
    },
    { immediate: true }
);
</script>

<template>
  <div class="fct-product-status-wrap">
    <Card.Container>
      <Card.Header title_size="small" border_bottom>
        <template #title>
          <LabelHint :title="$t('Group By')" :content="$t('Organize product variations by repeat interval(Monthly, Yearly) or payment term(One time, Subscription).')" />
        </template>

      </Card.Header>
       <Card.Body>
        <div class="fct-admin-summary-item" v-if="product.detail">
            <el-select v-model="product.detail.other_info.group_pricing_by" 
            :placeholder="$t('Group by')" @change="value => {productEditModel.onChangeInputField('group_pricing_by',value)}">
                <el-option :label="$t('Payment Term')" value="payment_type"/>
                <el-option :label="$t('Repeat Interval')" value="repeat_interval"/>
                <el-option :label="$t('None')" value="none"/>
            </el-select>
        </div>
<!--        <div class="fct-admin-summary-item" v-if="product.detail">-->
<!--            <el-checkbox v-model="product.detail.other_info.use_pricing_table" @change="value => {productEditModel.onChangeInputField('use_pricing_table',value)}" true-value="yes" false-value="no">-->
<!--                {{ $t('Enable Pricing Table') }}-->
<!--            </el-checkbox>-->
<!--        </div>-->
       </Card.Body>
    </Card.Container>
  </div>
</template>
