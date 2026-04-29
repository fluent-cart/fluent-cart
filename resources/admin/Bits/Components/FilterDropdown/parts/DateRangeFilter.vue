<template>
  <div :class="`fct-date-range-filter ${clearable ? 'is-clearable' : ''}`">
    <el-date-picker
        ref="datePickerRef"
        v-model="modelValue"
        :type="type"
        :range-separator="separator"
        :start-placeholder="startPlaceholder || translate('Start date')"
        :end-placeholder="endPlaceholder || translate('End date')"
        :unlink-panels="unlinkPanels"
        :clearable="clearable"
        :shortcuts="dateShortcuts"
        @change="onChange"
        :placement="placement"
        :disabled="disabled || !hasPro"
        v-translate-numbers="'translate-numbers'"
    />
    <DynamicIcon v-if="showArrow" name="ChevronDown" class="fct-applied-filter-expand-icon"/>
    <DynamicIcon
        v-if="hasPro && clearable"
        @click="clear"
        name="CircleClose"
        class="fct-applied-filter-clear-all"
    />
  </div>

</template>

<script setup>
import { computed, nextTick, ref, onBeforeUnmount } from 'vue';
import dateShortcuts from "@/Modules/Reports/Utils/dateShortCuts";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import DateUtil from "@/utils/support/Date";
import dayjs from "dayjs";
import {translateNumber} from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";


const props = defineProps({
  modelValue: Array,
  type: {
    type: String,
    default: 'daterange'
  },
  separator: {
    type: String,
    default: '-'
  },
  startPlaceholder: {
    type: String,
    default: ''
  },
  endPlaceholder: {
    type: String,
    default: ''
  },
  unlinkPanels: {
    type: Boolean,
    default: true
  },
  disabled: {
    type: Boolean,
    default: false
  },
  placement: {
    type: String,
    default: 'bottom-end'
  },
  showArrow: {
    type: Boolean,
    default: false
  },
  clearable: {
    type: Boolean,
    default: false
  },
  filterState: {
    type: [Object]
  }
});

const emit = defineEmits(['update:modelValue', 'change']);
const filter = props.filterState;
const datePickerRef = ref(null);
let pickerObserver = null;

const modelValue = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val)
});

const hasPro = computed(() =>
    AppConfig.get('app_config.isProActive')
);

function onChange(value) {
  emit('change', value);
}

function translatePickerElements(panel) {
  const elements = panel.querySelectorAll(
      '.el-date-table td span, ' +
      '.el-year-table td span, ' +
      '.el-month-table td span, ' +
      '.el-date-picker__header-label'
  );

  elements.forEach(elem => {
    if (elem.textContent && /\d/.test(elem.textContent) && !elem.dataset.translated) {
      elem.textContent = translateNumber(elem.textContent);
      elem.dataset.translated = 'true';
    }
  });
}

function startObserving(pickerPanel) {
  if (!pickerPanel) return;

  // Initial translation
  translatePickerElements(pickerPanel);

  // Create and start observer
  pickerObserver = new MutationObserver((mutations) => {
    translatePickerElements(pickerPanel);
  });

  pickerObserver.observe(pickerPanel, {
    childList: true,
    subtree: true
  });
}

function stopObserving() {
  if (pickerObserver) {
    pickerObserver.disconnect();
    pickerObserver = null;
  }
}

function onPanelVisible(visible) {
  if (visible) {
    nextTick(() => {
      // Find the picker panel associated with this date picker
      const pickerPanel = document.querySelector('.el-picker-panel');

      if (pickerPanel) {
        startObserving(pickerPanel);
      }

      // Handle shortcuts active state
      const shortcuts = document.querySelectorAll('.el-picker-panel__shortcut');
      shortcuts.forEach(btn => {
        btn.addEventListener('click', () => {
          shortcuts.forEach(s => s.classList.remove('active'));
          btn.classList.add('active');
        });
      });
    });
  } else {
    stopObserving();
  }
}

function clear() {
  filter.data.dateRange = [
    DateUtil.withTimezone(dayjs().subtract(1, 'month').format("YYYY-MM-DD 00:00:00")),
    DateUtil.withTimezone(dayjs().format("YYYY-MM-DD 23:59:59")),
  ];

  emit('change', filter.data.dateRange);
}

// Cleanup on component unmount
onBeforeUnmount(() => {
  stopObserving();
});
</script>
