<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import translate from "@/utils/translator/Translator";
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Arr from "@/utils/support/Arr";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ShippingClassDrawer from "@/Modules/Shipping/Components/ShippingClassDrawer.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import AppConfig from "@/utils/Config/AppConfig";
import Notify from "@/utils/Notify";

const props = defineProps({
  product: Object,
  productEditModel: Object,
})

const selectedClass = ref(
    Arr.get(props, 'product.detail.other_info.shipping_class')
);

const selectedClassInfo = ref();
const showClassDrawer = ref(false);

const loading = ref(false);
const shippingClasses = ref([]);
const shippingClassesUrl = AppConfig.get('admin_url') + 'settings/shipping/shipping_classes';

const fetchShippingClasses = (query = '') => {
  loading.value = true;
  shippingClasses.value = [];

  Rest.get('shipping/classes', {
    search: query
  }).then(response => {
    shippingClasses.value = response.shipping_classes.data;
    generateShippingInfo();
  }).finally(() => {
    loading.value = false;
  });
}

const onSave = (shipping_class) => {
  fetchShippingClasses();
  if (shipping_class.id) {
    selectedClass.value = shipping_class.id;
    props.productEditModel.onChangeInputField('shipping_class', selectedClass.value);
  }
}

const generateShippingInfo = () => {
  const selected = Arr.where(shippingClasses.value, 'id', selectedClass.value);
  selectedClassInfo.value = Arr.get(selected, '0');
}


const openAddClassDrawer = () => {
  showClassDrawer.value = true;
};

const saveShippingClass = () => {
  if (!selectedClass.value) {
    return;
  }

  Rest.post('products/'+props.product.ID+'/shipping-class', {
    shipping_class: selectedClass.value
  }).then(response => {
    Notify.success(response.message);
  }).catch(errors => {
    if (errors.status_code == '422') {
      Notify.validationErrors(errors);
    } else {
      Notify.error(errors.data?.message);
    }
  });
}
const removeShippingClass = () => {
  Rest.post('products/'+props.product.ID+'/shipping-class/remove').then(response => {
    Notify.success(response.message);
  }).catch(errors => {
    if (errors.status_code == '422') {
      Notify.validationErrors(errors);
    } else {
      Notify.error(errors.data?.message);
    }
  });
}

onMounted(() => {
  fetchShippingClasses();
})
</script>

<template>

  <div class="fct-product-simple-wrap">
    <Card.Container>
      <Card.Header border_bottom>
        <div class="flex items-center gap-1 justify-between w-full">
          <LabelHint :title="translate('Shipping Class')"
                     :content="translate('Use a shipping class to group products with similar shipping requirements. This allows you to set specific shipping rates for different types of items, such as \'Bulky Items\', \'Fragile Goods\'. Select an existing class from the search box or click + Add to create a new one. If no class is assigned, the product will use your store\'s default shipping rates.')" />

          <a :href="shippingClassesUrl" class="focus:outline-none focus:shadow-none w-4 h-4 inline-flex items-center justify-center" target="_blank">
            <DynamicIcon name="Redirect" class="w-3 h-3 text-primary-500 dark:text-gray-200" />
          </a>
        </div>
      </Card.Header>
      <Card.Body>
        <div class="fct-shipping-class-select-wrap relative">
          <el-select
              v-model="selectedClass"
              remote
              :placeholder="translate('Search Shipping Class')"
              :remote-method="fetchShippingClasses"
              :loading="loading"
              filterable
              clearable
              @change="saveShippingClass"
              @clear="removeShippingClass"
              popper-class="fct-shipping-class-select"
          >
            <el-option
                v-for="item in shippingClasses"
                :key="item.id"
                :label="item.name"
                :value="item.id"
            >
              <div class="fct-select-option-with-leftright-value">
                {{ item.name }}
                <span class="text-sm text-gray-500">{{ item.type === 'percentage' ?
                    item.cost + '%' :
                    CurrencyFormatter.formatNumber(item.cost * 100, true) }}</span>
              </div>
            </el-option>
          </el-select>
          <div class="pt-1.5 text-right">
            <el-button @click.stop="openAddClassDrawer" size="small" text>
              <DynamicIcon name="Plus" class="w-3 h-3"/> {{ translate('Add Shipping Class') }}
            </el-button>
          </div>
        </div>

        <div v-if="selectedClassInfo">
          <p class="mb-0 mt-2">{{
                /* translators: %s is the cost of the shipping class */
              translate('Cost: %s', (
            selectedClassInfo.type === 'percentage' ?
            selectedClassInfo.cost + '%' :
            CurrencyFormatter.formatNumber(selectedClassInfo.cost * 100, true)
          )) }}</p>
        </div>
      </Card.Body>
    </Card.Container>

    <ShippingClassDrawer
        v-model="showClassDrawer"
        @saved="onSave"
    />
  </div>
</template>
