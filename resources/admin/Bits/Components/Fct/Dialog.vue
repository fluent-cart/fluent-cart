<template>
  <component
      :is="drawer ? ElDrawer : ElDialog"
      :class="$attrs.class"
      :style="$attrs.style"
      :model-value="modelValue"
      :title="title"
      v-bind="drawer ? {size: width || '30%', withHeader} : {width, alignCenter, center, fullscreen}"
      :modal-class="modalClass"
      :append-to-body="appendToBody"
      :append-to="appendTo"
      :destroy-on-close="destroyOnClose"
      :close-on-click-modal="closeOnClickModal"
      :close-on-press-escape="closeOnPressEscape"
      :show-close="showClose"
      :before-close="beforeClose"
      :aria-describedby="ariaDescribedby"
      @update:model-value="emit('update:modelValue', $event)"
      @open="emit('open')"
      @opened="emit('opened')"
      @close="emit('close')"
      @closed="emit('closed')"
  >
    <template v-if="$slots.header" #header="scope">
      <slot name="header" v-bind="scope"/>
    </template>
    <slot/>
    <template v-if="$slots.footer" #footer>
      <slot name="footer"/>
    </template>
  </component>
</template>

<script setup>
import {ElDialog, ElDrawer} from 'element-plus';

// drawer: the same panel, sliding in from the side; `width` is then the drawer's width.
defineOptions({name: 'FctDialog', inheritAttrs: false});

defineProps({
  modelValue: {type: Boolean, default: false},
  drawer: {type: Boolean, default: false},
  title: {type: String, default: ''},
  width: {type: [String, Number], default: undefined},
  modalClass: {type: String, default: undefined},
  appendToBody: {type: Boolean, default: false},
  appendTo: {type: String, default: undefined},
  destroyOnClose: {type: Boolean, default: false},
  closeOnClickModal: {type: Boolean, default: true},
  closeOnPressEscape: {type: Boolean, default: true},
  showClose: {type: Boolean, default: true},
  beforeClose: {type: Function, default: undefined},
  ariaDescribedby: {type: String, default: undefined},
  // Dialog only.
  alignCenter: {type: Boolean, default: false},
  center: {type: Boolean, default: false},
  fullscreen: {type: Boolean, default: false},
  // Drawer only.
  withHeader: {type: Boolean, default: true},
});

const emit = defineEmits(['update:modelValue', 'open', 'opened', 'close', 'closed']);
</script>
