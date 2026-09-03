<script setup>
import translate from "@/utils/translator/Translator";
import {computed, onMounted, ref, watch} from "vue";
import Rest from "@/utils/http/Rest";
import Arr from "@/utils/support/Arr";
import Notify from "@/utils/Notify";
import * as Card from '@/Bits/Components/Card/Card.js';

const props = defineProps({
  product: Object,
  productEditModel: Object,
});

const taxClasses = ref([]);
const saving = ref(false);

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

const currentTaxExempt = computed(() => Arr.get(props, 'product.detail.other_info.tax_exempt', 'no'));
const currentTaxClass = computed(() => {
  const rawTaxClass = Arr.get(props, 'product.detail.other_info.tax_class', 'standard');

  return normalizeTaxClass(rawTaxClass) || 'standard';
});

const chargeTax = ref(currentTaxExempt.value !== 'yes');
const selectedClass = ref(currentTaxClass.value || 'standard');

watch([currentTaxExempt, currentTaxClass, taxClasses], ([taxExempt, taxClass]) => {
  if (saving.value) {
    return;
  }

  chargeTax.value = taxExempt !== 'yes';
  selectedClass.value = taxClass || 'standard';
});

const fetchTaxClasses = () => {
  Rest.get('tax/classes')
      .then(response => {
        taxClasses.value = response.classes || [];
      })
      .catch(() => {
        Notify.error(translate('Failed to load tax classes'));
      });
};

const syncTaxState = (taxExempt, taxClassSlug, rawStoredTaxClass = null) => {
  if (!Arr.get(props, 'product.detail')) {
    return;
  }

  if (!props.product.detail.other_info) {
    props.product.detail.other_info = {};
  }

  props.product.detail.other_info.tax_exempt = taxExempt;
  props.product.detail.other_info.tax_class = rawStoredTaxClass ?? taxClassSlug ?? 'standard';
};

const toggleTaxExempt = (value) => {
  const previousChargeTax = chargeTax.value;
  const previousSelectedClass = selectedClass.value;
  const nextTaxClass = selectedClass.value || currentTaxClass.value || 'standard';

  saving.value = true;
  Rest.post(`products/${props.product.ID}/tax-exempt`, {
    tax_exempt: value ? 'no' : 'yes',
    tax_class: nextTaxClass,
  }).then(response => {
    chargeTax.value = value;
    selectedClass.value = response.tax_class_slug || nextTaxClass;
    syncTaxState(
      response.tax_exempt || (value ? 'no' : 'yes'),
      response.tax_class_slug || nextTaxClass,
      response.tax_class ?? null
    );
    Notify.success(response.message);
  }).catch(errors => {
    chargeTax.value = previousChargeTax;
    selectedClass.value = previousSelectedClass;
    Notify.error(errors.data?.message || translate('Failed to update tax setting'));
  }).finally(() => {
    saving.value = false;
  });
};

const changeTaxClass = (slug) => {
  if (saving.value) {
    return;
  }

  const previousSelectedClass = selectedClass.value;
  selectedClass.value = slug;

  saving.value = true;
  Rest.post(`products/${props.product.ID}/tax-exempt`, {
    tax_exempt: 'no',
    tax_class: slug,
  }).then(response => {
    chargeTax.value = true;
    selectedClass.value = response.tax_class_slug || slug;
    syncTaxState(
      response.tax_exempt || 'no',
      response.tax_class_slug || slug,
      response.tax_class ?? null
    );
    Notify.success(response.message);
  }).catch(errors => {
    selectedClass.value = previousSelectedClass;
    Notify.error(errors.data?.message || translate('Failed to update tax class'));
  }).finally(() => {
    saving.value = false;
  });
};

const activeClassName = () => {
  const cls = taxClasses.value.find(c => c.slug === selectedClass.value);
  return cls ? cls.title : 'Standard';
};

onMounted(() => {
  fetchTaxClasses();
});
</script>

<template>
  <div class="fct-tax-class-control">
    <Card.Container>
        <Card.Body>
            <div class="mb-2 flex items-center gap-2">
                <el-checkbox
                v-model="chargeTax"
                :disabled="saving"
                    @change="toggleTaxExempt"
                >
                {{ translate('Charge tax on this product') }}
                </el-checkbox>
            </div>

            <div v-if="saving" class="flex items-center justify-center">
                <svg class="animate-spin h-4 w-4 text-system-light dark:text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
            
            <template v-else>
                <div v-if="chargeTax && taxClasses.length >= 1" class="flex items-center gap-1">
                    <span class="fct-tax-class-control__label text-sm text-system-mid dark:text-gray-300">
                        {{ translate('under') }}
                        <strong :class="selectedClass !== 'standard' ? 'text-red-500 dark:text-red-400' : ''">{{ activeClassName() }}</strong>
                        {{ translate('class') }}
                    </span>

                    <el-dropdown v-if="taxClasses.length > 1" :disabled="saving" trigger="click" @command="changeTaxClass">
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
        </Card.Body>
    </Card.Container>
  </div>
</template>

<style scoped>
.fct-tax-class-control {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.fct-tax-class-control__edit {
  cursor: pointer;
  transition: color 0.2s;
  display: inline-flex;
  align-items: center;
}
</style>
