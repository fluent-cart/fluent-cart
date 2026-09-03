<script setup>
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import { ref } from 'vue';

const props = defineProps({
  variant: { type: Object, required: true },
  productTitle: { type: String, default: '' },
});

const emit = defineEmits(['saved']);

const originalValue = ref(props.variant.sku || '');
const value = ref(props.variant.sku || '');
const dirty = ref(false);
const saving = ref(false);
const suggesting = ref(false);

const markDirty = () => { dirty.value = true; };

const cancelSkuEdit = () => {
  value.value = originalValue.value;
  dirty.value = false;
};

const saveSku = () => {
  saving.value = true;
  Rest.post('products/variants/group-bulk-update', {
    variant_ids: [props.variant.id],
    sku: value.value
  })
    .then(response => {
      originalValue.value = value.value;
      emit('saved', value.value);
      dirty.value = false;
      Notify.success(response.message);
    })
    .catch(errors => {
      if (errors.status_code == '422') {
        Notify.validationErrors(errors);
      } else {
        Notify.error(errors.data?.message);
      }
    })
    .finally(() => {
      saving.value = false;
    });
};

const generateSku = () => {
  suggesting.value = true;
  Rest.get('products/suggest-sku', {
    title: props.productTitle,
    variant_title: props.variant.variation_title || '',
    exclude_id: props.variant.id
  })
    .then(response => {
      value.value = response.sku;
      dirty.value = true;
    })
    .catch(errors => {
      Notify.error(errors.data?.message);
    })
    .finally(() => {
      suggesting.value = false;
    });
};
</script>

<template>
  <div class="fct-sku-input-wrap">
    <el-input
        v-model="value"
        size="small"
        :placeholder="translate('No SKU')"
        @input="markDirty"
        @keydown.enter.prevent="dirty && saveSku()"
        @keydown.escape="cancelSkuEdit"
    />
    <div class="fct-sku-edit-actions">
      <template v-if="dirty">
        <el-button
            size="small"
            type="primary"
            :title="translate('Save SKU')"
            :aria-label="translate('Save SKU')"
            :loading="saving"
            @click="saveSku"
        >
          <DynamicIcon name="Check" />
        </el-button>
        <el-button
            size="small"
            type="danger"
            :title="translate('Discard changes')"
            :aria-label="translate('Discard changes')"
            @click="cancelSkuEdit"
        >
          <DynamicIcon name="Close" />
        </el-button>
      </template>
      <el-button
          v-else
          size="small"
          class="fct-sku-suggest-btn"
          :loading="suggesting"
          :title="translate('Generate SKU')"
          @click="generateSku"
      >
        <DynamicIcon name="Generate" />
      </el-button>
    </div>
  </div>
</template>
