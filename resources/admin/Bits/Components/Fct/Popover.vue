<template>
  <ElPopconfirm
      v-if="confirm"
      ref="control"
      :class="$attrs.class"
      :style="$attrs.style"
      :title="title"
      :confirm-button-text="confirmButtonText"
      :cancel-button-text="cancelButtonText"
      :confirm-button-type="confirmButtonType"
      :icon-color="iconColor"
      :width="width"
      :disabled="disabled"
      :placement="placement"
      :teleported="teleported"
      :z-index="zIndex"
      v-bind="popperClass ? {popperClass} : {}"
      @confirm="emit('confirm', $event)"
      @cancel="emit('cancel', $event)"
  >
    <template v-if="$slots.reference" #reference>
      <slot name="reference"/>
    </template>
  </ElPopconfirm>
  <ElPopover
      v-else
      ref="control"
      :class="$attrs.class"
      :style="$attrs.style"
      :visible="visible"
      :placement="placement"
      :width="width"
      :trigger="trigger"
      :disabled="disabled"
      :teleported="teleported"
      :show-arrow="showArrow"
      :popper-class="popperClass"
      :popper-style="popperStyle"
      :role="role"
      @update:visible="emit('update:visible', $event)"
      @after-leave="emit('after-leave')"
  >
    <template v-if="$slots.reference" #reference>
      <slot name="reference"/>
    </template>
    <slot/>
  </ElPopover>
</template>

<script setup>
import {ref} from 'vue';
import {ElPopconfirm, ElPopover} from 'element-plus';

// confirm: a yes/no question (title + two buttons) instead of free content.
// In confirm mode popper-class is passed only when set: any popper-class key, even empty, replaces the
// popconfirm's own el-popover class and its 14px text.
defineOptions({name: 'FctPopover', inheritAttrs: false});

defineProps({
  confirm: {type: Boolean, default: false},
  // v-model:visible for a popover opened from code (trigger="manual" in Element Plus terms).
  visible: {type: Boolean, default: null},
  placement: {type: String, default: 'bottom'},
  width: {type: [String, Number], default: 150},
  trigger: {type: String, default: 'hover', validator: (value) => ['hover', 'click', 'focus', 'contextmenu'].includes(value)},
  disabled: {type: Boolean, default: false},
  teleported: {type: Boolean, default: true},
  showArrow: {type: Boolean, default: true},
  popperClass: {type: String, default: ''},
  popperStyle: {type: [String, Object], default: undefined},
  // The popup's role (Element Plus defaults to "dialog"); e.g. "alertdialog" for a confirm form.
  role: {type: String, default: undefined},
  // confirm only.
  title: {type: String, default: ''},
  confirmButtonText: {type: String, default: undefined},
  cancelButtonText: {type: String, default: undefined},
  confirmButtonType: {type: String, default: 'primary', validator: (value) => ['primary', 'danger'].includes(value)},
  iconColor: {type: String, default: undefined},
  // Element Plus overwrites a popconfirm's popper-class, so z-index is the only way to lift it over a high-z dialog.
  zIndex: {type: Number, default: undefined},
});

const emit = defineEmits(['confirm', 'cancel', 'update:visible', 'after-leave']);

const control = ref(null);
defineExpose({
  hide: () => control.value && control.value.hide(),
});
</script>
