<script setup>
import { computed } from 'vue';

const props = defineProps({
  variant: {
    required: true,
    type: Object
  },
  index: {
    required: true,
    type: Number
  }
});

const paymentTypeOptions = [
  {
    title: $t('Onetime'),
    value: 'onetime'
  },
  {
    title: $t('Subscription'),
    value: 'subscription'
  }
];

const paymentIntervalOptions = computed(() => {
  const intervals = window.fluentCartAdminApp?.subscription_intervals ?? [];
  // Convert backend format {label, value} to {title, value} for compatibility
  return intervals.map(interval => ({
    title: interval.label,
    value: interval.value
  }));
});
</script>

<template>
  <tr>
    <td>
      <el-input v-model="variant.variation_title" :placeholder="$t('Variation Title')"/>
    </td>

    <td>
      <el-input v-model="variant.item_price"/>
    </td>

    <td>
      <el-input v-model="variant.compare_price"/>
    </td>

    <td>
      <div>
        <el-select v-model="variant.other_info.payment_type">
          <el-option
              v-for="paymentType in paymentTypeOptions"
              :key="paymentType.value"
              :label="$t(paymentType.title)"
              :value="paymentType.value"
          />
        </el-select>

        <template v-if="variant.other_info.payment_type === 'subscription'">

          <el-select v-model="variant.other_info.repeat_interval">
            <el-option
                v-for="paymentInterval in paymentIntervalOptions"
                :key="paymentInterval.value"
                :label="paymentInterval.title"
                :value="paymentInterval.value"
            />
          </el-select>
          <el-input v-model="variant.other_info.times"/>

          <el-form-item>
            <el-switch v-model="variant.other_info.manage_setup_fee" active-value="yes" inactive-value="no"
                       :active-text="$t('Setup fee')">
            </el-switch>
          </el-form-item>

          <template v-if="variant?.other_info?.manage_setup_fee === 'yes'">
            <el-input
                :placeholder="$t('Setup fee name')" type="text"
                v-model="variant.other_info.signup_fee_name"
            >
            </el-input>

            <el-input :placeholder="$t('Setup fee amount')"
                      v-model.number="variant.other_info.signup_fee" :min="1">
              <template #prefix>
                <span v-html="appVars.shop.currency_sign"></span>
              </template>
              <template #append>
                <el-select class="fct-repeat-payment-every-select" v-model="variant.other_info.setup_fee_per_item"
                           :placeholder="$t('Charge per item')">
                  <el-option :label="$t('Per Order')" value="no"/>
                  <el-option :label="$t('Per Qty')" value="yes"/>
                </el-select>
              </template>
            </el-input>
          </template>
        </template>
      </div>
    </td>
  </tr>
</template>

<style scoped>

</style>
