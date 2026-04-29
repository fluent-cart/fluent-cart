<script setup>
import {onBeforeMount, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import labelHint from "@/Bits/Components/LabelHint.vue";

const props = defineProps({
  fromName: String,
  preSelectedFromVariant: {
    type: [String, Number]
  },

  preSelectedToVariants: {
    type: Array
  },
  preSelectedIsProrate: {
    type: [String, Number]
  },
  preSelectedDiscountAmount: {
    type: [String, Number]
  },
  pathOptions: Object,
  allVariants: Object,
  existingUpgradePathMap: Object,
  pathId: {
    type: [Number, String]
  }
})

const existingUpgradePathMapClone = ref({
  ...props.existingUpgradePathMap
});

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

const updatePath = () => {
  validationErrors.value = {};
  const data = {...form.value};
  if (!Array.isArray(data.to_variants) || data.to_variants.length === 0) {
    data['to_variants'] = [];
  }

  Rest.post(`products/upgrade-path/${props.pathId}/update`, {
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
        existingUpgradePathMapClone[form.value.from_variant]?.includes(variant.id) ||
        existingUpgradePathMapClone[form.value.from_variant]?.includes(variant.id.toString())
    ) && variant.id.toString() !== form.value.from_variant.toString();
  });
}

const prefillData = () => {
  form.value.from_variant = props.preSelectedFromVariant;
  form.value.is_prorate = props.preSelectedIsProrate;
  form.value.discount_amount = props.preSelectedDiscountAmount;
  form.value.to_variants = [];
  for (const variantId of props.preSelectedToVariants) {
    form.value.to_variants.push(parseInt(variantId));
  }
}
const handleDiscountInput = (value) => {
  // Remove leading zeros unless the value is just "0"
  let val = value?.toString().replace(/^0+(?=\d)/, '');
  if (val === '') val = '0';
  if (parseInt(val) < 0) val = '0';
  form.value.discount_amount = val;
};

onBeforeMount(() => {
  prefillData();
})


</script>

<template>
  <icon-button size="x-small" tag="button" @click="() =>{showModal = true;}">
    <DynamicIcon name="Edit"/>
  </icon-button>


  <el-drawer
      v-model="showModal"
      :title="$t('Update Upgrade Path')"
      @closed="()=>{
        showModal = false;
        form = {...dummyForm};
      }"
      @open="prefillData"
      append-to-body
  >
    <div class="fct-upgrade-path-drawer-content">
      <div class="fct-form-group">
        <label>{{ $t('From Plan') }}</label>
        <div class="text">
          {{ fromName }}
        </div>
      </div>

      <div class="fct-form-group">
        <label>{{ $t('To Plan(s)') }}</label>
        <el-select :disabled="!form.from_variant" v-model="form.to_variants" multiple placeholder="Select" clearable>
          <el-option
              v-for="variant in getVariantOption()"
              :key="variant.id"
              :label="variant.variation_title"
              :value="variant.id"
              :disabled="variant.id == form.from_variant"
          />
        </el-select>
        <validation-error
            v-if="validationErrors.hasOwnProperty('to_variants')"
            :validation-errors="validationErrors"
            field-key="to_variants"
        />
      </div>

      <div class="fct-form-group">
        <el-checkbox class="m-2" true-value="1" false-value="0" v-model="form.is_prorate">
          <label-hint class="flex items-center" :title="$t('Prorate')"
                      content="The prorated amount, based on the remaining days, will be credited to the new plan. Upgrading from one-time payment, the full amount will be credited.
          "/>
        </el-checkbox>
      </div>

      <div class="fct-form-group">
        <label>{{ $t('Discount Amount') }}</label>
        <el-input v-model="form.discount_amount" type="number" min="0" @input="handleDiscountInput"/>

        <validation-error
            v-if="validationErrors.hasOwnProperty('discount_amount')"
            :validation-errors="validationErrors"
            field-key="discount_amount"
        />
      </div>

    </div>

    <template #footer>
      <el-button @click="showModal = false" size="small">
        {{ $t('Cancel') }}
      </el-button>

      <el-button @click="updatePath" size="small" type="primary">
        {{ $t('Update') }}
      </el-button>
    </template>

  </el-drawer>

</template>
<style lang="scss">
.fct-form-group {
  .fct-label-hint span {
    margin: 0;
  }
}
</style>

