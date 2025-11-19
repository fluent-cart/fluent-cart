<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import LabelHint from "@/Bits/Components/LabelHint.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  product: Object,
  productEditModel: Object,
})
</script>

<template>
  <div class="fct-product-media-wrap">
    <Card.Container>
      <Card.Header border_bottom>
        <LabelHint
            :title="$t('Default Variant')"
            :content="$t('Select the default variant that users will see pre-selected on the product page.')"
            placement="top"/>
      </Card.Header>
      <Card.Body>
        <div class="fct-admin-summary-item">
          <el-select
              size="small"
              v-model="product.detail.default_variation_id"
              :placeholder="translate('Select Default Variant')"
              @change="value => {productEditModel.setHasChange(true)}"
              clearable
          >
            <el-option
                v-for="(variant) in product.variants"
                :key="variant.id"
                :label="variant.variation_title"
                :value="variant.id.toString()"
            >
            </el-option>
          </el-select>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
