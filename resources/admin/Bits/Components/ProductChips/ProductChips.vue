<template>
  <div class="fct-product-chips" ref="rootRef">
    <!-- Chips take keyboard focus; mousedown.prevent keeps a click focusing the select's search input. -->
    <template v-for="chip in shownChips" :key="chip.key">
      <el-tooltip v-if="chip.variations.length" :disabled="tooltipsDisabled" :trigger="['hover', 'focus']" placement="bottom" popper-class="fct-tooltip">
        <template #content>
          <div class="fct-product-chip-tooltip__title">{{ translate('Picked variations') }}</div>
          <div v-for="variation in chip.variations" :key="variation">{{ variation }}</div>
        </template>
        <span class="fct-product-chip" role="button" tabindex="0" :aria-label="variationsLabel(chip)" @mousedown.prevent>
          <el-tag disable-transitions><span class="fct-product-chip__text">{{ chip.text }}</span></el-tag>
        </span>
      </el-tooltip>
      <el-tag v-else disable-transitions><span class="fct-product-chip__text" :title="chip.text">{{ chip.text }}</span></el-tag>
    </template>

    <el-tooltip v-if="hiddenChips.length" :disabled="tooltipsDisabled" :trigger="['hover', 'focus']" placement="bottom" popper-class="fct-tooltip">
      <template #content>
        <div class="fct-product-chip-tooltip__title">{{ translate('Also picked') }}</div>
        <div v-for="chip in hiddenChips" :key="chip.key">{{ chip.text }}</div>
      </template>
      <span class="fct-product-chip" role="button" tabindex="0" :aria-label="hiddenLabel" @mousedown.prevent>
        <el-tag disable-transitions>+{{ hiddenChips.length }}</el-tag>
      </span>
    </el-tooltip>

    <!-- Off-screen copy of every chip, so the visible count can be fitted to the row width. -->
    <div v-if="fit" class="fct-product-chips__measure" ref="measureRef" aria-hidden="true">
      <el-tag v-for="chip in chips" :key="chip.key" disable-transitions>
        <span class="fct-product-chip__text">{{ chip.text }}</span>
      </el-tag>
      <el-tag disable-transitions>+{{ chips.length }}</el-tag>
    </div>
  </div>
</template>

<script setup>
import {computed, nextTick, onBeforeUnmount, onMounted, ref, watch} from "vue";
import translate from "@/utils/translator/Translator";
import {buildProductChips, fitChipCount} from "./productChips";

const props = defineProps({
  // Product groups as described in productChips.js buildProductChips().
  groups: {
    type: Array,
    default: () => []
  },
  // Upper bound on visible chips; by default as many as fit on one row.
  max: {
    type: Number,
    default: Infinity
  },
  // Fit the count to the row width. Turn off where the select sizes to its content,
  // since measuring a box that shrinks with the chips would shrink it further.
  fit: {
    type: Boolean,
    default: true
  },
  // Set while the dropdown is open so tooltips don't cover the tree.
  tooltipsDisabled: {
    type: Boolean,
    default: false
  }
});

const rootRef = ref(null);
const measureRef = ref(null);
const fitCount = ref(Math.min(2, props.max));

const chips = computed(() => buildProductChips(props.groups));
const visibleCount = computed(() => props.fit ? fitCount.value : props.max);
const shownChips = computed(() => chips.value.slice(0, visibleCount.value));
const hiddenChips = computed(() => chips.value.slice(visibleCount.value));

// Tooltips are hover/focus only, so screen readers get the same details as the chip label.
/* translators: %1$s: chip text, e.g. "Shirt (2 of 5)", %2$s: comma-separated variation names */
const variationsLabel = (chip) => translate('%1$s. Picked variations: %2$s', chip.text, chip.variations.join(', '));

const hiddenLabel = computed(() => {
  /* translators: %1$s: number of products not shown, %2$s: comma-separated product names */
  return translate('%1$s more picked: %2$s', hiddenChips.value.length, hiddenChips.value.map(chip => chip.text).join(', '));
});

// The row to fill is the select's tag area; our own box only wraps what is shown.
const availableWidth = () => {
  const root = rootRef.value;
  const row = root.closest('.el-select__selection') || root.parentElement;
  // Keep a few px for the search input el-select puts after the tags.
  return row.clientWidth - 12;
};

const measure = () => {
  if (!props.fit || !rootRef.value || !measureRef.value) {
    return;
  }

  const tags = Array.from(measureRef.value.children);
  const plusTag = tags.pop();
  const gap = parseFloat(getComputedStyle(rootRef.value).columnGap) || 0;

  fitCount.value = fitChipCount(
    tags.map(tag => tag.offsetWidth),
    plusTag.offsetWidth,
    gap,
    availableWidth(),
    props.max
  );
};

let observer = null;

onMounted(() => {
  measure();
  const root = rootRef.value;
  const row = root && (root.closest('.el-select__selection') || root.parentElement);
  if (row && typeof ResizeObserver !== 'undefined') {
    observer = new ResizeObserver(() => measure());
    observer.observe(row);
  }
});

watch(() => [chips.value, props.max], () => nextTick(measure));

onBeforeUnmount(() => {
  if (observer) {
    observer.disconnect();
  }
});
</script>
