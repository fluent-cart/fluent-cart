<template>
  <div class="fct-discount-modal">
    <div>
      <div class="fct-discount-modal__row">
        <fct-select
            v-model="initialDiscount.type"
            :label="$t('Discount type')"
            :placeholder="$t('Select')"
            :options="discountOptions"
            @change="value => {
							error = ''
						}"
        />
        <Field :label="$t('Discount value')">
          <fct-input type="number" :controls="false" :min="1" v-model.number="initialDiscount.value" @keyup="isDisabledApplyButton">
            <template #prefix>
              <span v-if="initialDiscount.type === 'amount'" v-html="currencySign"></span>
              <span v-else>%</span>
            </template>
          </fct-input>
          <span style="color: red;">{{ error }}</span>
        </Field>
      </div>
      <div class="fct-discount-modal__reason">
        <Field :label="$t('Reason for discount')">
          <fct-input v-model="initialDiscount.reason" minlength="1" maxlength="255" show-word-limit/>
          <div class="form-note">
            <p>{{ $t('Customer can see this reason') }}</p>
          </div>
        </Field>
      </div>
    </div>
    <div class="dialog-footer">
      <fct-button type="info" @click="cancelDiscount" soft>
        {{ $t('Cancel') }}
      </fct-button>

      <fct-button type="primary" :disabled="isDisabledApplyButton()" @click="applyDiscount">
        {{ $t('Apply') }}
      </fct-button>
    </div>
  </div>

</template>

<script>
import {ref, reactive} from "vue";
import {formatNumber} from "@/Bits/productService";
import AppConfig from "@/utils/Config/AppConfig";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import translate from "@/utils/translator/Translator";
import Field from "@/Bits/Components/Fct/Field.vue";

export default {
  name: "DiscountModal",
  components: {Field},
  props: ['discount', 'showModal', 'totalAmount', 'currency'],
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
    const currencySign = AppConfig.get('currency_signs', {})[props.currency] || AppConfig.get('shop.currency_sign', '$');

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
          error.value = translate('Discount should not exceed the full product price (%s).', CurrencyFormatter.formatForOrder(props.totalAmount, props.currency));
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
      currencySign,
    }
  }
}
</script>

<style scoped>

</style>
