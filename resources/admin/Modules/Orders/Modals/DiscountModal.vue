<template>
  <div class="fct-discount-modal">
    <el-form label-position="top">
      <el-row :gutter="20">
        <el-col :md="12" :sm="12" :xs="24">
          <el-form-item :label="$t('Discount type')">
            <el-select v-model="initialDiscount.type" :placeholder="$t('Select')" @change="value => {
							error = ''
						}">
              <el-option v-for="item in discountOptions"
                         :key="item.value" :label="item.label" :value="item.value">
              </el-option>
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :md="12" :sm="12" :xs="24">
          <el-form-item :label="$t('Discount value')">
            <el-input type="number" :min="1" v-model.number="initialDiscount.value" @keyup="isDisabledApplyButton">
              <template #prefix>
                <span v-if="initialDiscount.type === 'amount'" v-html="appVars.shop.currency_sign"></span>
                <span v-else>%</span>
              </template>
            </el-input>
            <span style="color: red;">{{ error }}</span>
          </el-form-item>
        </el-col>
      </el-row>
      <el-row>
        <el-col :md="24" :sm="12" :xs="24">
          <el-form-item :label="$t('Reason for discount')">
            <el-input v-model="initialDiscount.reason" minlength="1" maxlength="255" show-word-limit>
            </el-input>
            <div class="form-note">
              <p>{{ $t('Customer can see this reason') }}</p>
            </div>
          </el-form-item>
        </el-col>
      </el-row>
    </el-form>
    <div class="dialog-footer">
      <el-button type="info" @click="cancelDiscount" soft>
        {{ $t('Cancel') }}
      </el-button>

      <el-button type="primary" :disabled="isDisabledApplyButton()" @click="applyDiscount">
        {{ $t('Apply') }}
      </el-button>
    </div>
  </div>

</template>

<script>
import {ref, reactive} from "vue";
import {formatNumber, formatMoney} from "@/Bits/productService";
import translate from "@/utils/translator/Translator";

export default {
  name: "DiscountModal",
  props: ['discount', 'showModal', 'totalAmount'],
  setup(props, {emit}) {

    // Create a local copy of the discount for the modal's form inputs
    const initialDiscount = reactive({...props.discount});
    const discountOptions = ref([
      {
        label: translate('Amount'),
        value: 'amount'
      },
      // {
      //   label: translate('Percentage'),
      //   value: 'percentage'
      // }
    ]);
    const error = ref('');

    const isDisabledApplyButton = () => {
      error.value = '';
      const parseDiscountValue = parseInt(initialDiscount.value);
      let discountAmount = parseDiscountValue ? parseDiscountValue : 0;
      const orderTotalAmount = props.totalAmount / 100;

      if (initialDiscount.type == 'percentage') {
        discountAmount = (orderTotalAmount * parseDiscountValue) / 100;

        if (parseDiscountValue > 100 || discountAmount > orderTotalAmount) {
          error.value = translate('Discount should not exceed the full product price (100%).');
        }
        return isNaN(parseDiscountValue) || parseDiscountValue < 1 || parseDiscountValue > 100
      } else {
        if (discountAmount > orderTotalAmount) {
          /* translators: %s - order total amount */
          error.value = translate('Discount should not exceed the full product price (%s).', formatMoney(orderTotalAmount));
        }
        return isNaN(parseDiscountValue) || parseDiscountValue < 1 || discountAmount > orderTotalAmount
      }
    };

    const applyDiscount = () => {
      const parseDiscountValue = parseInt(initialDiscount.value);

      if (isNaN(parseDiscountValue) || parseDiscountValue < 1) {
        initialDiscount.value = 1;
      } else {
        // Emit the updated discount values
        emit('whenDiscountEditIsDone', false, {...initialDiscount});
      }
    };

    const cancelDiscount = () => {
      emit('emitCancelDiscountModal');
    };

    return {
      discountOptions,
      applyDiscount,
      cancelDiscount,
      initialDiscount,
      isDisabledApplyButton,
      error,
    }
  }
}
</script>

<style scoped>

</style>
