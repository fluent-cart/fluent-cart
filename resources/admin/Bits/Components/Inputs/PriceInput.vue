<template>
  <el-input
      :class="errorClass"
      :id="id"
      :placeholder="placeholder"
      :model-value="displayValue"
      :disabled="disabled"
      @blur="onBlur"
      @input="onInput"
      @keydown="onKeydown">
    <template #prefix>
      <span v-html="currencySign"></span>
    </template>
    <template v-if="$slots.suffix" #suffix>
      <slot name="suffix"/>
    </template>
  </el-input>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import AppConfig from "@/utils/Config/AppConfig";

const props = defineProps({
  modelValue: {
    type: [Number, String],
    default: '',
  },
  numberFormat: {
    type: String,
    default: 'comma_dot', // 'comma_dot' = 10,000.59  |  'dot_comma' = 10.000,59
    validator: (val) => ['comma_dot', 'dot_comma'].includes(val),
  },
  id: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: '',
  },
  min: {
    type: Number,
    default: 0,
  },
  errorClass: {
    type: String,
    default: '',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['update:modelValue', 'change']);

const currencySign = computed(() => AppConfig.get('shop.currency_sign', '$'));

const isDotComma = computed(() => props.numberFormat === 'dot_comma');
const shopDecimalSeparator = computed(() => {
  const configuredSeparator = AppConfig.get('shop.decimal_separator', '');

  if (configuredSeparator === 'comma') {
    return ',';
  }

  if (configuredSeparator === 'dot') {
    return '.';
  }

  return isDotComma.value ? ',' : '.';
});
const shopThousandSeparator = computed(() => {
  return shopDecimalSeparator.value === ',' ? '.' : ',';
});
const shopLocale = computed(() => {
  return shopDecimalSeparator.value === ',' ? 'de-DE' : 'en-US';
});

// Allowed characters: digits + dot + comma
const allowedChars = /[\d.,]/;

/**
 * Block any key that is not a digit, dot, comma, or a control key
 */
const onKeydown = (event) => {
  const controlKeys = ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Tab', 'Enter', 'Home', 'End'];
  if (controlKeys.includes(event.key)) return;
  // Allow Ctrl/Cmd combos (copy, paste, select all, etc.)
  if (event.ctrlKey || event.metaKey) return;
  // Block anything that's not a digit, dot, or comma
  if (!allowedChars.test(event.key)) {
    event.preventDefault();
  }
};

const stripLeadingZeros = (value) => {
  return String(value || '').replace(/^0+(?=\d)/, '');
};

const sanitizeInput = (value) => {
  return String(value ?? '').replace(/[^\d.,]/g, '');
};

const normalizeTypingValue = (value) => {
  const cleaned = sanitizeInput(value);

  if (!cleaned) {
    return '';
  }

  const lastDotIndex = cleaned.lastIndexOf('.');
  const lastCommaIndex = cleaned.lastIndexOf(',');
  const decimalIndex = Math.max(lastDotIndex, lastCommaIndex);

  if (decimalIndex === -1) {
    return stripLeadingZeros(cleaned.replace(/[.,]/g, ''));
  }

  const integerDigits = stripLeadingZeros(cleaned.slice(0, decimalIndex).replace(/[^\d]/g, ''));
  const fractionDigits = cleaned.slice(decimalIndex + 1).replace(/[^\d]/g, '');
  const separatorChar = cleaned.charAt(decimalIndex);
  const separatorCount = (cleaned.match(new RegExp(`\\${separatorChar}`, 'g')) || []).length;
  const hasTrailingSeparator = decimalIndex === cleaned.length - 1;

  const hasBothSeparatorTypes = cleaned.includes('.') && cleaned.includes(',');
  const shouldTreatAsThousandsSeparator = !hasTrailingSeparator
    && !hasBothSeparatorTypes
    && separatorChar === shopThousandSeparator.value
    && (separatorCount > 1 || fractionDigits.length > 2);

  if (shouldTreatAsThousandsSeparator) {
    return stripLeadingZeros(cleaned.replace(/[^\d]/g, ''));
  }

  const normalizedInteger = integerDigits || '0';

  if (hasTrailingSeparator) {
    return `${normalizedInteger}.`;
  }

  return `${normalizedInteger}.${fractionDigits.slice(0, 2)}`;
};

const parseNormalizedValue = (value) => {
  if (value === null || value === undefined || value === '') {
    return NaN;
  }

  const normalizedValue = typeof value === 'number'
    ? String(value)
    : normalizeTypingValue(value);

  if (!normalizedValue) {
    return NaN;
  }

  return parseFloat(normalizedValue.replace(/\.$/, ''));
};

const formatViewValue = (value) => {
  const parsed = parseNormalizedValue(value);

  if (isNaN(parsed)) {
    return '';
  }

  return parsed.toLocaleString(shopLocale.value, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
};

const formatMaskedValue = (value) => {
  if (!value) {
    return '';
  }

  const hasTrailingSeparator = value.endsWith('.');
  const [integerPart = '', decimalPart = ''] = value.split('.');
  const maskedInteger = (integerPart || '0').replace(/\B(?=(\d{3})+(?!\d))/g, shopThousandSeparator.value);

  if (hasTrailingSeparator) {
    return `${maskedInteger}${shopDecimalSeparator.value}`;
  }

  if (decimalPart) {
    return `${maskedInteger}${shopDecimalSeparator.value}${decimalPart}`;
  }

  return maskedInteger;
};

const toNormalizedModelValue = (value, applyMin = false) => {
  const parsed = parseNormalizedValue(value);

  if (isNaN(parsed)) {
    return '';
  }

  const normalizedNumber = applyMin ? normalizeMinValue(parsed) : parsed;

  return normalizedNumber.toFixed(2);
};

const normalizeMinValue = (num) => {
  if (props.min === null || props.min === undefined || isNaN(num)) {
    return num;
  }

  return num < props.min ? props.min : num;
};

// --- Internal state ---
const displayValue = ref('');
const internalRaw = ref('');
// Track whether the user is actively editing (between first input and blur)
const dirty = ref(false);

const syncFromModel = (value) => {
  const normalizedValue = toNormalizedModelValue(value);

  if (!normalizedValue) {
    displayValue.value = '';
    internalRaw.value = '';
    return;
  }

  displayValue.value = formatViewValue(normalizedValue);
  internalRaw.value = normalizedValue;
};

const onInput = (value) => {
  const normalizedTypingValue = normalizeTypingValue(value);

  internalRaw.value = normalizedTypingValue;
  displayValue.value = formatMaskedValue(normalizedTypingValue);
  dirty.value = true;

  if (normalizedTypingValue === '') {
    emit('update:modelValue', '');
    return;
  }

  const normalizedModelValue = toNormalizedModelValue(normalizedTypingValue);

  if (normalizedModelValue !== '') {
    emit('update:modelValue', normalizedModelValue);
  }
};

const onBlur = () => {
  const source = dirty.value ? internalRaw.value : props.modelValue;

  if (source === '') {
    emit('update:modelValue', '');
    emit('change', '');
    displayValue.value = '';
    internalRaw.value = '';
  } else {
    const normalizedValue = toNormalizedModelValue(source, true);

    if (normalizedValue === '') {
      syncFromModel(props.modelValue);
    } else {
      emit('update:modelValue', normalizedValue);
      emit('change', normalizedValue);
      displayValue.value = formatViewValue(normalizedValue);
      internalRaw.value = normalizedValue;
    }
  }

  dirty.value = false;
};

watch([() => props.modelValue, () => props.numberFormat], ([newVal]) => {
  if (!dirty.value) {
    syncFromModel(newVal);
  }
});

syncFromModel(props.modelValue);
</script>
