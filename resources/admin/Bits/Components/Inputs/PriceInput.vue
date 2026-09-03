<template>
  <el-input
      :class="errorClass"
      :id="id"
      :size="size"
      :placeholder="placeholder"
      :model-value="displayValue"
      :disabled="disabled"
      @focus="onFocus"
      @blur="onBlur"
      @input="onInput"
      @keydown="onKeydown">
    <template #prefix>
      <span v-html="currencySign"></span>
    </template>
    <template v-if="$slots.suffix" #suffix>
      <slot name="suffix"/>
    </template>
    <template v-if="$slots.append" #append>
      <slot name="append"/>
    </template>
  </el-input>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import AppConfig from "@/utils/Config/AppConfig";
import {
  resolveSeparators,
  stripLeadingZeros,
  sanitizeInput,
  normalizeTypingValue as normalizeTypingValueCore,
  parseNormalizedValue as parseNormalizedValueCore,
  dollarsToCents,
  centsToDollarValue,
} from "@/Bits/moneyInput";

/**
 * The single dollars <-> cents boundary in the admin.
 *
 * `modelValue` is in CENTS (integer), matching the database, every REST read
 * response and every REST write payload. The merchant still sees and types
 * dollars — this component owns the conversion so no other file has to.
 *
 *   modelValue 400000  ->  displays "4,000"  ->  emits 400000
 *
 * Never hand this component a dollar value, and never divide by 100 before
 * passing a price in. If a value looks 100x wrong on screen, the caller is
 * pre-converting.
 */
const props = defineProps({
  // CENTS, not dollars. See the note above.
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
  // Forwarded to el-input so compact contexts (bulk tables) keep their sizing.
  size: {
    type: String,
    default: '',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

// `change` carries native change semantics: it fires on blur only when the
// merchant leaves a different value than the field held when they focused it.
// It deliberately does NOT compare against `modelValue`, which a caller that
// syncs on `update:modelValue` (every inline price table does) has already
// moved by blur time — that comparison made `change` unreachable for them.
// `focus`/`blur` are forwarded because el-input owns those listeners, so they
// are not reachable through attribute fallthrough.
const emit = defineEmits(['update:modelValue', 'change', 'focus', 'blur']);

const currencySign = computed(() => AppConfig.get('shop.currency_sign', '$'));

const separators = computed(() => resolveSeparators(
  AppConfig.get('shop.decimal_separator', ''),
  props.numberFormat
));
const shopDecimalSeparator = computed(() => separators.value.decimal);
const shopThousandSeparator = computed(() => separators.value.thousand);
const shopLocale = computed(() => separators.value.locale);

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

// Thin wrappers so the shop's thousands separator does not have to be threaded
// through every call site below. The parsing itself lives in Bits/moneyInput.js,
// shared with the CSV importer so both boundaries read a value the same way.
const normalizeTypingValue = (value) => normalizeTypingValueCore(value, shopThousandSeparator.value);

const parseNormalizedValue = (value) => parseNormalizedValueCore(value, shopThousandSeparator.value);

const formatViewValue = (value) => {
  const parsed = parseNormalizedValue(value);

  if (isNaN(parsed)) {
    return '';
  }

  return parsed.toLocaleString(shopLocale.value, {
    minimumFractionDigits: 0,
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

// --- Cents <-> dollars boundary -------------------------------------------
// Everything above this line parses and formats DOLLARS. `props.modelValue`
// and every emit are CENTS. These two functions are the only crossing point.
// Both come from Bits/moneyInput.js — dollarValueToCents is dollarsToCents
// bound to the shop's separators, so a typed "1.234,56" and an imported
// "1.234,56" resolve to the same number of cents.

const dollarValueToCents = (dollars) => dollarsToCents(dollars, shopThousandSeparator.value);

// --- Internal state ---
const displayValue = ref('');
const internalRaw = ref('');
// Track whether the user is actively editing (between first input and blur)
const dirty = ref(false);

const syncFromModel = (value) => {
  const normalizedValue = toNormalizedModelValue(centsToDollarValue(value));

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

  if (normalizedModelValue !== '' && normalizedModelValue !== centsToDollarValue(props.modelValue)) {
    emit('update:modelValue', dollarValueToCents(normalizedModelValue));
  }
};

// The normalized dollars string the field held when it was focused, so blur
// can tell an actual edit from a tab-through. See the `change` note above.
const valueAtFocus = ref('');

const onFocus = () => {
  valueAtFocus.value = toNormalizedModelValue(centsToDollarValue(props.modelValue));
  emit('focus');
};

const onBlur = () => {
  // internalRaw is already a dollars string; props.modelValue is cents, so it
  // has to cross the boundary before the dollars parser sees it.
  const source = dirty.value ? internalRaw.value : centsToDollarValue(props.modelValue);

  // What this field settled on, in cents. Emitted with `blur` below rather
  // than re-reading props.modelValue, which is still the pre-blur value at
  // this point — the parent has not re-rendered from the emit above yet.
  let committedCents = props.modelValue;

  if (source === '') {
    committedCents = '';
    emit('update:modelValue', '');
    if (valueAtFocus.value !== '') {
      emit('change', '');
    }
    displayValue.value = '';
    internalRaw.value = '';
  } else {
    const normalizedValue = toNormalizedModelValue(source, true);

    if (normalizedValue === '') {
      syncFromModel(props.modelValue);
    } else {
      const centsValue = dollarValueToCents(normalizedValue);
      committedCents = centsValue;
      emit('update:modelValue', centsValue);
      if (normalizedValue !== valueAtFocus.value) {
        emit('change', centsValue);
      }
      displayValue.value = formatViewValue(normalizedValue);
      internalRaw.value = normalizedValue;
    }
  }

  dirty.value = false;
  valueAtFocus.value = '';

  emit('blur', committedCents);
};

watch([() => props.modelValue, () => props.numberFormat], ([newVal]) => {
  if (!dirty.value) {
    syncFromModel(newVal);
  }
});

syncFromModel(props.modelValue);
</script>
