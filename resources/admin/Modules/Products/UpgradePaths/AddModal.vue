<script setup>
import {ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import Theme from "@/utils/Theme";
import VariationSelector from "@/Bits/Components/VariationSelector.vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";


const props = defineProps({
  pathOptions: Object,
  allVariants: Object,
  existingUpgradePathMap: Object,
  productId: {
    type: [Number, String]
  }
})

const emit = defineEmits(['pathGenerated']);

const showModal = ref(false);
const openModal = () => {
  showModal.value = true;
}

const dummyForm = {
  from_variant: '',
  to_variants: [],
  is_prorate: '0',
  discount_amount: '0'
};

const form = ref({...dummyForm});
const validationErrors = ref({});
const currentCurrencySign = AppConfig.get('shop.currency_sign');

const savePath = () => {
  validationErrors.value = {};
  const data = {...form.value};
  if (!Array.isArray(data.to_variants) || data.to_variants.length === 0) {
    data['to_variants'] = [];
  }

  Rest.post(`products/${props.productId}/upgrade-path`, {
    ...data
  })
      .then((response) => {
        Notify.success(response.message);
        form.value = {...dummyForm};
        showModal.value = false;
        emit('pathGenerated');
      })
      .catch((error) => {
        validationErrors.value = error;
        if (error.message) {
          return Notify.error(error.message);
        }
      })
}

const getVariantOption = () => {
  if (!form.value.from_variant) {
    return [];
  }

  return props.allVariants.filter(variant => {
    return !(
        props.existingUpgradePathMap[form.value.from_variant]?.includes(variant.id) ||
        props.existingUpgradePathMap[form.value.from_variant]?.includes(variant.id.toString())
    ) && variant.id !== form.value.from_variant;
  });
}

const handleDiscountInput = (value) => {
  // Remove leading zeros unless the value is just "0"
  let val = value?.toString().replace(/^0+(?=\d)/, '');
  if (val === '') val = '0';
  if (parseInt(val) < 0) val = '0';
  form.value.discount_amount = val;
};

defineExpose({
  openModal
});


</script>

<template>
  <el-drawer
      :title="translate('Add Upgrade Path')"
      v-model="showModal"
      @closed="()=>{
        showModal = false;
        form = {...dummyForm};
      }"
      append-to-body
      :class="Theme.isAdminBarEnabled() ? 'admin-bar-enable': ''"
      :close-on-click-modal="false"
  >
    <div class="fct-upgrade-path-drawer-content">
      <div class="fct-form-group">
        <label>{{ translate('From Plan') }}</label>
        <el-select v-model="form.from_variant"
                   :placeholder="translate('Select')"
                   popper-class="fct-variation-select">
          <el-option
              v-for="variant in pathOptions"
              :key="variant.id"
              :label="variant.variation_title"
              :value="variant.id"
              :disabled="form.to_variants.includes(variant.id)"
          >
            <VariationSelector :variant="variant"/>
          </el-option>
        </el-select>

        <validation-error
            v-if="validationErrors.hasOwnProperty('from_variant')"
            :validation-errors="validationErrors"
            field-key="from_variant"
        />
      </div>

      <div class="fct-form-group">
        <label>{{ translate('To Plan(s)') }}</label>

        <el-select :disabled="!form.from_variant"
                   v-model="form.to_variants"
                   multiple
                   :placeholder="translate('Select')"
                   clearable
                   popper-class="fct-variation-select">
          <el-option
              v-for="variant in getVariantOption()"
              :key="variant.id"
              :label="variant.variation_title"
              :value="variant.id"
              :disabled="variant.id == form.from_variant"
          >
            <VariationSelector :variant="variant"/>
          </el-option>
        </el-select>

        <validation-error
            v-if="validationErrors.hasOwnProperty('to_variants')"
            :validation-errors="validationErrors"
            field-key="to_variants"
        />
      </div>

      <div class="fct-form-group">
        <el-checkbox true-value="1" false-value="0" v-model="form.is_prorate">{{ translate('Prorate') }}</el-checkbox>
      </div>

      <div class="fct-form-group">
        <label>{{ translate('Discount Amount') }}</label>
        <el-input v-model="form.discount_amount" type="number" :min="0" @input="handleDiscountInput">
          <template #prepend>{{ currentCurrencySign }}</template>
        </el-input>
        <validation-error
            v-if="validationErrors.hasOwnProperty('discount_amount')"
            :validation-errors="validationErrors"
            field-key="discount_amount"
        />
      </div>
    </div>

    <template #footer>
      <el-button @click="showModal = false">
        {{ translate('Cancel') }}
      </el-button>

      <el-button @click="savePath" type="primary">
        {{ translate('Save') }}
      </el-button>
    </template>

  </el-drawer>
</template>

