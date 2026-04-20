<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import translate from "@/utils/translator/Translator";
import {ref, computed} from "vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";

const props = defineProps({
  product: Object,
  productEditModel: Object,
  storeSettings: {
    type: Object,
    default: () => ({})
  }
})

const weightUnit = computed(() => props.storeSettings.weight_unit || 'kg');
const dimensionUnit = computed(() => props.storeSettings.dimension_unit || 'cm');

const weight = ref(props.product?.variants?.[0]?.weight || null);
const length = ref(props.product?.variants?.[0]?.length || null);
const width = ref(props.product?.variants?.[0]?.width || null);
const height = ref(props.product?.variants?.[0]?.height || null);

const onFieldChange = (field, value) => {
  props.product.variants.forEach((variant, index) => {
    props.product.variants[index][field] = value;
    props.productEditModel.ensureVariationIndex(index);

    if (!props.productEditModel.data.product_changes.variants) {
      props.productEditModel.data.product_changes.variants = [];
    }

    props.productEditModel.data.product_changes.variants[index][field] = value;
    props.productEditModel.data.product_changes.variants[index]['id'] = variant.id;
  });

  props.productEditModel.setHasChange(true);
}
</script>

<template>
  <div class="fct-product-simple-wrap">
    <Card.Container>
      <Card.Header border_bottom>
        <LabelHint :title="translate('Weight & Dimensions')"
                   :content="translate('Set the physical attributes for this product. These values are used for shipping rate calculations.')" />
      </Card.Header>
      <Card.Body>
        <div class="fct-physical-attributes-grid">
          <el-form label-position="top" size="default">
            <el-form-item>
              <template #label>
                {{ translate('Weight (%s)', weightUnit) }}
              </template>
              <el-input-number
                  v-model="weight"
                  :precision="4"
                  :min="0"
                  :step="0.1"
                  controls-position="right"
                  :placeholder="translate('Weight')"
                  @change="onFieldChange('weight', $event)"
                  class="w-full"
              />
            </el-form-item>

            <div class="flex gap-2">
              <el-form-item class="flex-1">
                <template #label>
                  {{ translate('Length (%s)', dimensionUnit) }}
                </template>
                <el-input-number
                    v-model="length"
                    :precision="4"
                    :min="0"
                    :step="0.1"
                    controls-position="right"
                    :placeholder="translate('Length')"
                    @change="onFieldChange('length', $event)"
                    class="w-full"
                />
              </el-form-item>

              <el-form-item class="flex-1">
                <template #label>
                  {{ translate('Width (%s)', dimensionUnit) }}
                </template>
                <el-input-number
                    v-model="width"
                    :precision="4"
                    :min="0"
                    :step="0.1"
                    controls-position="right"
                    :placeholder="translate('Width')"
                    @change="onFieldChange('width', $event)"
                    class="w-full"
                />
              </el-form-item>

              <el-form-item class="flex-1">
                <template #label>
                  {{ translate('Height (%s)', dimensionUnit) }}
                </template>
                <el-input-number
                    v-model="height"
                    :precision="4"
                    :min="0"
                    :step="0.1"
                    controls-position="right"
                    :placeholder="translate('Height')"
                    @change="onFieldChange('height', $event)"
                    class="w-full"
                />
              </el-form-item>
            </div>
          </el-form>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
