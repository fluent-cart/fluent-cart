<script setup>
import SharedVariantItemBox from "@/Modules/Products/parts/SharedVariantItemBox.vue";
import translate from "@/utils/translator/Translator";
import {computed, onMounted, ref, watch} from "vue";
import Rest from "@/utils/http/Rest";
import Arr from "@/utils/support/Arr";
import Notify from "@/utils/Notify";

const props = defineProps({
  variant: Object,
  productEditModel: Object,
  fieldKey: String,
  modeType: String,
  isGroupMode: { type: Boolean, default: false },
});

const taxClasses = ref([]);

const normalizeTaxClass = (rawTaxClass) => {
  if (!rawTaxClass) {
    return 'standard';
  }

  const slugMatch = taxClasses.value.find(tc => String(tc.slug) === String(rawTaxClass));
  if (slugMatch) {
    return slugMatch.slug;
  }

  const idMatch = taxClasses.value.find(tc => String(tc.id) === String(rawTaxClass));
  if (idMatch) {
    return idMatch.slug;
  }

  return rawTaxClass;
};

const currentTaxExempt = computed(() => {
  return Arr.get(props.variant, 'other_info.tax_exempt', 'no');
});

const currentTaxClass = computed(() => {
  const rawTaxClass = Arr.get(props.variant, 'other_info.tax_class', 'standard');

  return normalizeTaxClass(rawTaxClass) || 'standard';
});

const chargeTax = ref(currentTaxExempt.value !== 'yes');
const selectedClass = ref(currentTaxClass.value || 'standard');

watch([currentTaxExempt, currentTaxClass, taxClasses], ([taxExempt, taxClass]) => {
  chargeTax.value = taxExempt !== 'yes';
  selectedClass.value = taxClass || 'standard';
});

const ensureVariantOtherInfo = () => {
  if (!props.variant.other_info) {
    props.variant.other_info = {};
  }
};

const syncTaxState = (taxExempt, taxClassSlug) => {
  ensureVariantOtherInfo();

  props.variant.other_info.tax_exempt = taxExempt;
  props.variant.other_info.tax_class = taxClassSlug || 'standard';

  if (props.productEditModel && props.fieldKey) {
    props.productEditModel.updatePricingOtherValue('tax_exempt', props.variant.other_info.tax_exempt, props.fieldKey, props.variant, props.modeType);
    props.productEditModel.updatePricingOtherValue('tax_class', props.variant.other_info.tax_class, props.fieldKey, props.variant, props.modeType);
  }
};

const fetchTaxClasses = () => {
  Rest.get('tax/classes')
      .then(response => {
        taxClasses.value = response.classes || [];
      })
      .catch(() => {
        Notify.error(translate('Failed to load tax classes'));
      });
};

const toggleTaxExempt = (value) => {
  const nextTaxClass = selectedClass.value || currentTaxClass.value || 'standard';

  chargeTax.value = value;
  selectedClass.value = nextTaxClass;
  syncTaxState(value ? 'no' : 'yes', nextTaxClass);
};

const changeTaxClass = (slug) => {
  selectedClass.value = slug;
  chargeTax.value = true;
  syncTaxState('no', slug);
};

const activeClassName = () => {
  const cls = taxClasses.value.find(c => c.slug === selectedClass.value);
  return cls ? cls.title : 'Standard';
};

const activeClassNameGroup = () => {
  const taxClass = props.variant?.other_info?.tax_class;
  if (!taxClass) return translate('Unchanged');
  const cls = taxClasses.value.find(c => c.slug === taxClass);
  return cls ? cls.title : taxClass;
};

const groupChangeTaxClass = (slug) => {
  if (!props.variant.other_info) return;
  props.variant.other_info.tax_class = slug;
};

onMounted(() => {
  fetchTaxClasses();
});
</script>

<template>
  <SharedVariantItemBox>
    <template #label>{{ translate('Tax') }}</template>

    <!-- Group-edit mode: select-based controls so "Unchanged" is a valid state -->
    <div v-if="isGroupMode" class="fct-tax-class-control">
      <div class="fct-inline-select-wrap">
        <label>{{ translate('Tax Exemption') }}:</label>

        <el-select
            v-model="variant.other_info.tax_exempt"
            size="small"
            class="fct-inline-select"
            placement="bottom"
            popper-class="fct-group-select-popper"
        >
          <el-option value="__unchanged__" :label="translate('Unchanged')" />
          <el-option value="no" :label="translate('Charge tax')" />
          <el-option value="yes" :label="translate('Tax exempt')" />
        </el-select>
      </div>

      <div
          v-if="variant.other_info.tax_exempt === 'no' && taxClasses.length >= 1"
          class="fct-tax-class-control__class-row text-sm text-system-mid dark:text-gray-300"
      >
        <span>
          {{ translate('Class:') }}
          <strong :class="(variant.other_info.tax_class && variant.other_info.tax_class !== 'standard') ? 'text-red-500 dark:text-red-400' : ''">
            {{ activeClassNameGroup() }}
          </strong>
        </span>
        <el-dropdown v-if="taxClasses.length > 1" trigger="click" @command="groupChangeTaxClass">
          <span class="fct-tax-class-control__edit text-system-light hover:text-system-dark dark:text-gray-400 dark:hover:text-gray-50" :title="translate('Change tax class')">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" width="14" height="14">
              <path d="M13.488 2.513a1.75 1.75 0 0 0-2.475 0L3.22 10.303a.75.75 0 0 0-.178.31l-.893 3.125a.75.75 0 0 0 .926.926l3.125-.893a.75.75 0 0 0 .31-.178l7.79-7.79a1.75 1.75 0 0 0 0-2.475l-.812-.815ZM12.074 3.574a.25.25 0 0 1 .354 0l.812.812a.25.25 0 0 1 0 .354L5.45 12.529l-1.838.526.525-1.838 7.937-7.643Z"/>
            </svg>
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item
                  :command="null"
                  :class="{ 'is-active': !variant.other_info.tax_class }"
              >
                {{ translate('Unchanged') }}
              </el-dropdown-item>
              <el-dropdown-item
                  v-for="tc in taxClasses"
                  :key="tc.slug"
                  :command="tc.slug"
                  :class="{ 'is-active': variant.other_info.tax_class === tc.slug }"
              >
                {{ tc.title }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </div>

    <!-- Normal single-variant mode: checkbox -->
    <div v-else class="fct-tax-class-control">
      <el-checkbox
          class="fct-tax-class-control__checkbox"
          v-model="chargeTax"
          @change="toggleTaxExempt"
      >
        {{ translate('Charge tax on this variation') }}
      </el-checkbox>
      <template v-if="chargeTax && taxClasses.length >= 1">
        <div class="fct-tax-class-control__class-row text-sm text-system-mid dark:text-gray-300">
          <span>
            {{ translate('Class:') }}
            <strong :class="selectedClass !== 'standard' ? 'text-red-500 dark:text-red-400' : ''">{{ activeClassName() }}</strong>
          </span>
          <el-dropdown v-if="taxClasses.length > 1" trigger="click" @command="changeTaxClass">
            <span class="fct-tax-class-control__edit text-system-light hover:text-system-dark dark:text-gray-400 dark:hover:text-gray-50" :title="translate('Change tax class')">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" width="14" height="14">
                <path d="M13.488 2.513a1.75 1.75 0 0 0-2.475 0L3.22 10.303a.75.75 0 0 0-.178.31l-.893 3.125a.75.75 0 0 0 .926.926l3.125-.893a.75.75 0 0 0 .31-.178l7.79-7.79a1.75 1.75 0 0 0 0-2.475l-.812-.815ZM12.074 3.574a.25.25 0 0 1 .354 0l.812.812a.25.25 0 0 1 0 .354L5.45 12.529l-1.838.526.525-1.838 7.937-7.643Z"/>
              </svg>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item
                    v-for="tc in taxClasses"
                    :key="tc.slug"
                    :command="tc.slug"
                    :class="{ 'is-active': selectedClass === tc.slug }"
                >
                  {{ tc.title }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>

      </template>
    </div>
  </SharedVariantItemBox>
</template>
