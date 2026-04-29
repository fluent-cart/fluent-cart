<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import translate from "@/utils/translator/Translator";
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Arr from "@/utils/support/Arr";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import TaxClassDrawer from "@/Modules/Tax/Components/TaxClassDrawer.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Notify from "@/utils/Notify";
import AppConfig from "@/utils/Config/AppConfig";

const props = defineProps({
  product: Object,
  productEditModel: Object,
})

const selectedClass = ref(
    Arr.get(props, 'product.detail.other_info.tax_class')
);

const selectedClassInfo = ref();
const showClassDrawer = ref(false);

const loading = ref(false);
const taxClasses = ref([]);
const taxClassesUrl = AppConfig.get('admin_url') + 'settings/tax_settings';

const fetchTaxClasses = (query = '') => {
  loading.value = true;
  taxClasses.value = [];

  Rest.get('tax/classes', {
    search: query
  }).then(response => {
    taxClasses.value = response.tax_classes;
    generateTaxInfo();
  }).finally(() => {
    loading.value = false;
  });
}

const onSave = (tax_class) => {
  fetchTaxClasses();
  if (tax_class.id) {
    selectedClass.value = tax_class.id;
    props.productEditModel.onChangeInputField('tax_class', selectedClass.value);
  }
}

const generateTaxInfo = () => {
  const selected = Arr.where(taxClasses.value, 'id', selectedClass.value);
  selectedClassInfo.value = Arr.get(selected, '0');
}

const openAddClassDrawer = () => {
  showClassDrawer.value = true;
};

const saveTaxClass = () => {
  if (!selectedClass.value) {
    return;
  }

  Rest.post('products/'+props.product.ID+'/tax-class', {
    tax_class: selectedClass.value
  }).then(response => {
    Notify.success(response.message);
    generateTaxInfo();
  }).catch(errors => {
    if (errors.status_code == '422') {
      Notify.validationErrors(errors);
    } else {
      Notify.error(errors.data?.message);
    }
  });
}

const removeTaxClass = () => {

  Rest.post('products/'+props.product.ID+'/tax-class/remove').then(response => {
    Notify.success(response.message);
    generateTaxInfo();
  }).catch(errors => {
    if (errors.status_code == '422') {
      Notify.validationErrors(errors);
    } else {
      Notify.error(errors.data?.message);
    }
  });
}

onMounted(() => {
  fetchTaxClasses();
})
</script>

<template>

  <div class="fct-product-simple-wrap">
    <Card.Container>
      <Card.Header border_bottom>
        <div class="flex items-center gap-1 justify-between w-full">
          <LabelHint :title="translate('Tax Class')"
                     :content="translate('Use a tax class to group products with similar tax treatment. This allows you to set specific tax rates for different types of items, such as \'Standard Rate\', \'Reduced Rate\', \'Zero Rate\'. Select an existing class from the search box or click + Add to create a new one. If no class is assigned, the product will use your store\'s default tax rates.')" />

          <a :href="taxClassesUrl" class="focus:outline-none focus:shadow-none w-4 h-4 inline-flex items-center justify-center" target="_blank">
            <DynamicIcon name="Redirect" class="w-3 h-3 text-primary-500 dark:text-gray-200" />
          </a>
        </div>
      </Card.Header>
      <Card.Body>
        <div class="fct-tax-class-select-wrap relative">
          <el-select
              v-model="selectedClass"
              remote
              :placeholder="translate('Search Tax Class')"
              :remote-method="fetchTaxClasses"
              :loading="loading"
              filterable
              clearable
              @change="saveTaxClass"
              @clear="removeTaxClass"
              popper-class="fct-tax-class-select"
          >
            <el-option
                v-for="item in taxClasses"
                :key="item.id"
                :label="item.title"
                :value="item.id"
            >
              <div class="fct-select-option-with-leftright-value">
                {{ item.title }}
                <!-- <span class="text-sm text-gray-500">{{ item.description || translate('No description') }}</span> -->
              </div>
            </el-option>
          </el-select>
          <div class="pt-1.5 text-right">
            <el-button @click.stop="openAddClassDrawer" size="small" text>
              <DynamicIcon name="Plus" class="w-3 h-3"/> {{ translate('Add Tax Class') }}
            </el-button>
          </div>
        </div>

        <div v-if="selectedClassInfo">
          <p class="mb-0 mt-2">{{
              /* translators: %s is the description of the tax class */
              translate('Description: %s', selectedClassInfo.description || translate('No description available'))
            }}</p>
        </div>
      </Card.Body>
    </Card.Container>

    <TaxClassDrawer
        v-model="showClassDrawer"
        @saved="onSave"
    />
  </div>
</template>
