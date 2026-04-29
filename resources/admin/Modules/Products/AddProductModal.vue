<template>
  <div class="fct-product-create">
    <div class="fct-product-step">
      <el-form label-position="top" :model="product" require-asterisk-position="right">
        <el-form-item :label="product.detail.other_info.is_bundle_product === 'yes' ? translate('Bundle Title') : translate('Product Title')" required>
          <el-input
              type="text"
              v-model="product.post_title"
              :placeholder="product.detail.other_info.is_bundle_product === 'yes'? translate('Bundle Title') : translate('Product Title')"
              minlength="3"
              maxlength="200"
              show-word-limit
              autofocus
              ref="fcProductTitle"
              @keydown.enter="onEnter"
          />
        </el-form-item>

        <el-form-item>
          <ul class="fct-product-item-selector">
            <li
                v-for="fulfilmentType in fulfilmentTypes"
                :key="fulfilmentType.type"
                :class="{ active: fulfilmentType.type === product.detail.fulfillment_type }"
                @click="handleSelectProductType(fulfilmentType.type)"
            >
              <div class="fct-product-item-selector-content">
                <DynamicIcon :name="fulfilmentType.icon"/>
                {{ fulfilmentType.title }}
              </div>
              <div class="fct-product-item-selector-dot-wrap">
                <span class="fct-product-item-selector-dot"></span>
              </div>
            </li>
          </ul>
        </el-form-item>

        <el-form-item>
          <div class="fct-bundle-product-condition">
            <el-checkbox v-model="product.detail.other_info.is_bundle_product" true-value="yes" false-value="no">
              {{ translate('Add as a Bundle') }}
            </el-checkbox>
            <span class="condition-text">
              {{ translate('Combine multiple items into one package') }}
            </span>
          </div>
        </el-form-item>
      </el-form>
    </div>

    <div class="dialog-footer">
      <el-button
          :disabled="!product.post_title || creatingProduct"
          :loading="creatingProduct"
          @click="createProduct"
          type="primary"
      >
        {{ creatingProduct ? translate('Creating') : translate('Add Product') }}
      </el-button>
    </div>
  </div>
</template>

<script setup>
import {ref, nextTick, onMounted} from 'vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import Notify from '@/utils/Notify';
import translate from '@/utils/translator/Translator';
import {useRouter} from 'vue-router';
import Rest from "@/utils/http/Rest";

const props = defineProps({
  customModal: String
});

const emit = defineEmits(['process_custom', 'update:createProductModal']);

const router = useRouter();

const product = ref({
  post_title: '',
  post_content: '',
  post_excerpt: '',
  post_date: new Date(),
  post_status: 'draft',
  detail: {
    fulfillment_type: 'digital',
    variation_type: 'simple',
    manage_stock: '0',
    manage_downloadable: '0',
    other_info: {
      use_pricing_table: 'yes',
      group_pricing_by: 'repeat_interval',
      active_editor: 'wp-editor',
      sold_individually: 'no'
    },
  },
});

const fulfilmentTypes = [
  {type: 'physical', title: translate('Physical Product'), icon: 'Gift'},
  {type: 'digital', title: translate('Digital Product'), icon: 'Code'},
];

const creatingProduct = ref(false);
const fcProductTitle = ref(null);

const handleSelectProductType = (type) => {
  product.value.detail.fulfillment_type = type;
};

const createProduct = () => {
  creatingProduct.value = true;


  // Replace with your actual POST method (use axios or injected method)
  Rest.post('products', {...product.value})
      .then((response) => {
        Notify.success(response.message);

        if (props.customModal === 'order_modal') {
          const item = {
            id: response.data.product_variants.id,
            object_id: response.data.product_variants.post_id,
            variation_id: response.data.product_variants.id,
            item_name: response.data.product_variants.variation_title,
            item_price: response.data.product_variants.item_price,
            quantity: 1,
            stock: 100,
            object_type: response.data.product_details.fulfillment_type,
            item_total: response.data.product_variants.item_price,
            line_total: response.data.product_variants.item_price,
            tax_amount: 0,
            discount_total: 0,
          };

          emit('process_custom', item);
          emit('update:createProductModal', false);
        } else {
          router.push({name: 'product_edit', params: {product_id: response.data.ID}});
        }
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {
        creatingProduct.value = false;
      });
};

const onEnter = (event) => {
  event.stopPropagation();
  event.preventDefault();
  createProduct();
};

onMounted(() => {
  nextTick(() => {
    setTimeout(() => {
      fcProductTitle.value?.focus();
    }, 10);
  });
});
</script>
