<template>
  <Field v-bind="$props" v-slot="field">
    <ElSelect
        ref="control"
        :id="field.id"
        :class="[$attrs.class, {'el-select--x-small': size === 'x-small'}]"
        :style="$attrs.style"
        :model-value="modelValue"
        :placeholder="placeholder"
        :size="size === 'x-small' ? '' : size"
        :disabled="disabled"
        :clearable="clearable"
        :filterable="filterable"
        :multiple="multiple"
        :value-key="valueKey"
        :collapse-tags="collapseTags"
        :collapse-tags-tooltip="collapseTagsTooltip"
        :max-collapse-tags="maxCollapseTags"
        :tag-type="tagType"
        :allow-create="allowCreate"
        :default-first-option="defaultFirstOption"
        :reserve-keyword="reserveKeyword"
        :filter-method="filterMethod"
        :remote="remote"
        :remote-method="remoteMethod"
        :remote-show-suffix="remoteShowSuffix"
        :loading="loading"
        :loading-text="loadingText"
        :no-data-text="noDataText"
        :no-match-text="noMatchText"
        :value-on-clear="valueOnClear"
        :empty-values="emptyValues"
        :placement="placement"
        :suffix-icon="suffixIcon"
        :popper-class="popperClass"
        :aria-label="ariaLabel"
        :aria-describedby="ariaDescribedby"
        :aria-invalid="ariaInvalid"
        @update:model-value="emit('update:modelValue', $event)"
        @change="emit('change', $event)"
        @visible-change="emit('visible-change', $event)"
        @remove-tag="emit('remove-tag', $event)"
        @clear="emit('clear')"
        @focus="emit('focus', $event)"
        @blur="emit('blur', $event)"
        @click="emit('click', $event)"
        @keydown="emit('keydown', $event)"
        @keydown.capture="letEscapeReachDialog"
        @mousedown="emit('mousedown', $event)"
    >
      <!-- Keyed by position: values may repeat or be objects. -->
      <template v-for="(option, index) in options" :key="index">
        <ElOptionGroup v-if="option.options" :label="option.label" :disabled="option.disabled">
          <ElOption
              v-for="(child, childIndex) in option.options"
              :key="childIndex"
              :label="child.label"
              :value="child.value"
              :disabled="child.disabled"
          >
            <template v-if="$slots.option" #default><slot name="option" :option="child"/></template>
          </ElOption>
        </ElOptionGroup>
        <ElOption v-else :label="option.label" :value="option.value" :disabled="option.disabled">
          <template v-if="$slots.option" #default><slot name="option" :option="option"/></template>
        </ElOption>
      </template>
      <template v-if="$slots.label" #label="scope"><slot name="label" v-bind="scope"/></template>
      <template v-if="$slots.tag" #tag="scope"><slot name="tag" v-bind="scope"/></template>
      <template v-if="$slots.prefix" #prefix><slot name="prefix"/></template>
      <template v-if="$slots.header" #header><slot name="header"/></template>
      <template v-if="$slots.footer" #footer><slot name="footer"/></template>
      <template v-if="$slots.empty" #empty><slot name="empty"/></template>
      <template v-if="$slots.loading" #loading><slot name="loading"/></template>
    </ElSelect>
  </Field>
</template>

<script setup>
import {ref} from 'vue';
import {ElOption, ElOptionGroup, ElSelect} from 'element-plus';
import Field from './Field.vue';
import {fieldProps} from './fieldProps';

defineOptions({name: 'FctSelect', inheritAttrs: false});

defineProps({
  ...fieldProps,
  modelValue: {type: [String, Number, Boolean, Array, Object], default: ''},
  // [{label, value, disabled}], or groups: [{label, options: [...]}].
  options: {type: Array, default: () => []},
  placeholder: {type: String, default: undefined},
  size: {type: String, default: '', validator: (value) => ['', 'default', 'small', 'x-small'].includes(value)},
  disabled: {type: Boolean, default: false},
  clearable: {type: Boolean, default: false},
  filterable: {type: Boolean, default: false},
  multiple: {type: Boolean, default: false},
  // Object option values: the key that identifies them.
  valueKey: {type: String, default: 'value'},
  collapseTags: {type: Boolean, default: false},
  collapseTagsTooltip: {type: Boolean, default: false},
  maxCollapseTags: {type: Number, default: 1},
  tagType: {type: String, default: 'info'},
  allowCreate: {type: Boolean, default: false},
  defaultFirstOption: {type: Boolean, default: false},
  reserveKeyword: {type: Boolean, default: true},
  filterMethod: {type: Function, default: undefined},
  remote: {type: Boolean, default: false},
  remoteMethod: {type: Function, default: undefined},
  remoteShowSuffix: {type: Boolean, default: false},
  loading: {type: Boolean, default: false},
  loadingText: {type: String, default: undefined},
  noDataText: {type: String, default: undefined},
  noMatchText: {type: String, default: undefined},
  valueOnClear: {type: [String, Number, Boolean, Function], default: undefined},
  emptyValues: {type: Array, default: undefined},
  placement: {type: String, default: 'bottom-start'},
  suffixIcon: {type: [String, Object], default: undefined},
  popperClass: {type: String, default: ''},
  ariaLabel: {type: String, default: undefined},
  ariaDescribedby: {type: String, default: undefined},
  ariaInvalid: {type: [Boolean, String], default: undefined},
});

const emit = defineEmits(['update:modelValue', 'change', 'visible-change', 'remove-tag', 'clear', 'focus', 'blur', 'click', 'keydown', 'mousedown']);

const control = ref(null);

// Element Plus stops every Escape on the select, so a dialog around it never closed.
// With the menu closed, let this Escape bubble on; with it open, Escape only closes the menu.
const letEscapeReachDialog = (event) => {
  if (event.key === 'Escape' && event.target.getAttribute('aria-expanded') !== 'true') {
    event.stopPropagation = () => {};
  }
};
defineExpose({
  focus: () => control.value && control.value.focus(),
  blur: () => control.value && control.value.blur(),
});
</script>
