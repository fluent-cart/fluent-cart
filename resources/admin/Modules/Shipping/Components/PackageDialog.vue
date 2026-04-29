<script setup>
import {ref, computed, watch} from 'vue';
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  },
  editData: {
    type: Object,
    default: null
  },
  saving: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['update:modelValue', 'save']);

const defaultForm = () => ({
  slug: '',
  name: '',
  type: 'box',
  length: null,
  width: null,
  height: null,
  dimension_unit: 'cm',
  weight: null,
  weight_unit: 'kg',
  is_default: false
});

const form = ref(defaultForm());

const isEdit = computed(() => !!props.editData);

const dialogTitle = computed(() => isEdit.value ? translate('Edit package') : translate('Add package'));

const saveButtonText = computed(() => isEdit.value ? translate('Update package') : translate('Add package'));

const isHeightDisabled = computed(() => form.value.type === 'envelope');

const canSave = computed(() => {
  const hasBase = form.value.name && form.value.length > 0 && form.value.width > 0;
  return form.value.type === 'envelope'
    ? hasBase
    : hasBase && form.value.height > 0;
});

const packageTypes = [
  {value: 'box', label: 'Box'},
  {value: 'envelope', label: 'Envelope'},
  {value: 'soft_package', label: 'Soft package'}
];

const dimensionUnits = [
  {label: 'cm', value: 'cm'},
  {label: 'mm', value: 'mm'},
  {label: 'in', value: 'in'},
  {label: 'm', value: 'm'}
];

const weightUnits = [
  {label: 'kg', value: 'kg'},
  {label: 'g', value: 'g'},
  {label: 'lbs', value: 'lbs'},
  {label: 'oz', value: 'oz'}
];

const typeIcons = {
  box: 'Box',
  envelope: 'Envelope',
  soft_package: 'SoftPackage'
};

watch(() => props.modelValue, (val) => {
  if (val) {
    if (props.editData) {
      form.value = {...defaultForm(), ...props.editData};
    } else {
      form.value = defaultForm();
    }
  }
});

const selectType = (type) => {
  form.value.type = type;
  if (type === 'envelope') {
    form.value.height = null;
  }
};

const handleSave = () => {
  if (!form.value.slug) {
    let slug = form.value.name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '');
    if (!slug) {
      slug = 'package-' + Date.now();
    }
    form.value.slug = slug;
  }
  emit('save', {...form.value});
};

const handleClose = () => {
  emit('update:modelValue', false);
};
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    @update:model-value="handleClose"
    :title="dialogTitle"
    :aria-label="dialogTitle"
    append-to-body
    :close-on-click-modal="false"
    class="fct-package-dialog"
  >
    <div class="fct-package-form">
      <!-- Package Type -->
      <div class="fct-package-type-section">
        <div class="fct-package-label">{{ translate('Package type') }}</div>
        <div class="fct-package-type-selector">
          <div
            v-for="pType in packageTypes"
            :key="pType.value"
            class="fct-package-type-card"
            :class="{'is-active': form.type === pType.value}"
            role="button"
            tabindex="0"
            :aria-pressed="form.type === pType.value"
            @click="selectType(pType.value)"
            @keydown.enter="selectType(pType.value)"
            @keydown.space.prevent="selectType(pType.value)"
          >
            <span class="fct-package-type-icon">
                <DynamicIcon :name="typeIcons[pType.value]"/>
            </span>
            <span class="fct-package-type-label">{{ translate(pType.label) }}</span>
          </div>
        </div>
      </div>

      <!-- Dimensions + Weight Row -->
      <div class="fct-package-dimensions-row">
        <div class="fct-package-dim-inputs">
            <div class="fct-package-dim-field">
                <div class="fct-package-label">
                    {{ translate('Length') }}
                </div>
                <el-input-number
                    v-model="form.length"
                    :min="0"
                    :precision="2"
                    :step="0.5"
                    :controls="false"
                    :placeholder="'0'"
                    size="default"
                    class="w-full"
                />
            </div>
            <div class="fct-package-dim-field">
                <div class="fct-package-label">
                    {{ translate('Width') }}
                </div>
                <el-input-number
                    v-model="form.width"
                    :min="0"
                    :precision="2"
                    :step="0.5"
                    :controls="false"
                    :placeholder="'0'"
                    size="default"
                    class="w-full"
                />
            </div>
            <div class="fct-package-dim-field">
                <div class="fct-package-label" :class="{'text-gray-300': isHeightDisabled}">
                    {{ translate('Height') }}
                </div>
                <el-input-number
                    v-model="form.height"
                    :min="0"
                    :precision="2"
                    :step="0.5"
                    :controls="false"
                    :disabled="isHeightDisabled"
                    :placeholder="isHeightDisabled ? '' : '0'"
                    size="default"
                    class="w-full"
                />
            </div>
            <div class="fct-package-unit-field">
                <div class="fct-package-label">&nbsp;</div>

                <el-select v-model="form.dimension_unit" size="default" class="w-full">
                <el-option v-for="u in dimensionUnits" :key="u.value" :label="u.label" :value="u.value" />
                </el-select>
            </div>
        </div>

        <div class="fct-package-weight-group">
            <div class="fct-package-label">
                {{ translate('Weight (empty)') }}
            </div>

            <div class="fct-package-weight-input">
                <el-input
                    v-model="form.weight"
                    type="number"
                    :min="0"
                    :precision="4"
                    :step="0.1"
                    :controls="false"
                    :placeholder="'0'"
                    size="default"
                    class="fct-product-weight-number"
                >
                    <template #append>
                        <el-select v-model="form.weight_unit" size="default" class="fct-product-weight-unit">
                            <el-option v-for="u in weightUnits" :key="u.value" :label="u.label" :value="u.value" />
                        </el-select>
                    </template>
                </el-input>
            </div>
        </div>
      </div>

      <!-- Package Name -->
      <div class="fct-package-name-field">
        <div class="fct-package-label">
            {{ translate('Package name') }}
        </div>

        <el-input v-model="form.name" :placeholder="translate('e.g. Medium box')" size="default" />
      </div>

      <!-- Default checkbox -->
      <div class="fct-package-default-check">
        <el-checkbox v-model="form.is_default">
          {{ translate('Use as default package') }}
        </el-checkbox>
        <div class="fct-package-default-check-desc">
          {{ translate('Used to calculate rates at checkout and pre-selected when assigning packages to products') }}
        </div>
      </div>
    </div>

    <template #footer>
      <span class="dialog-footer">
        <el-button @click="handleClose">{{ translate('Cancel') }}</el-button>
        <el-button type="primary" @click="handleSave" :disabled="!canSave || saving" :loading="saving">
          {{ saveButtonText }}
        </el-button>
      </span>
    </template>
  </el-dialog>
</template>
