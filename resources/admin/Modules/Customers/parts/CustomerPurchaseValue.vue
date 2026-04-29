<template>
  <span class="text" v-if="Object.keys(parsedValue).length">
    <el-popover
        placement="bottom"
        trigger="hover"
        popper-class="fct-popover"
        v-if="otherValues.length"
    >
      <template #reference>
        <span class="font-medium">
          <span v-html="formatNumber(firstValue.value, true, false, firstValue.currency)"></span>
          +{{ otherValues.length }}
        </span>
      </template>

      <div class="purchase-values">
        <div
            v-for="(val, index) in otherValues"
            :key="val.currency"
            class="purchase-value-item"
        >
          <span v-html="formatNumber(val.value, true, false, val.currency)"></span>
        </div>
      </div>
    </el-popover>

    <span v-else>
      <span v-html="formatNumber(firstValue.value, true, false, firstValue.currency)"></span>
    </span>

    {{ translate('Lifetime Value') }}
  </span>

  <span class="text" v-else>
    {{ translate('Lifetime Value') }}: {{ translate('No Data') }}
  </span>
</template>

<script setup>
import { computed } from 'vue';
import translate from "@/utils/translator/Translator";

const props = defineProps({
  value: {
    type: [Object, String],
    required: true,
  },
});

// Parse the value
const parsedValue = computed(() => {
  if (typeof props.value === 'string') {
    try {
      return JSON.parse(props.value);
    } catch (e) {
      return {};
    }
  }
  return props.value && typeof props.value === 'object' ? props.value : {};
});

// Get the first value to display outside
const firstValue = computed(() => {
  const entries = Object.entries(parsedValue.value || {});
  if (!entries.length) return { currency: '', value: 0 };
  const [currency, value] = entries[0];
  return { currency, value };
});

// Get the remaining values for popover
const otherValues = computed(() => {
  const entries = Object.entries(parsedValue.value || {});
  return entries.slice(1).map(([currency, value]) => ({ currency, value }));
});
</script>

