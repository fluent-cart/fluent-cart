<template>
  <ElButton
      v-bind="{...dataAttrs, ...linkAttrs}"
      :id="id"
      :class="[$attrs.class, {'el-button--x-small': size === 'x-small'}]"
      :style="$attrs.style"
      :type="type === 'default' ? '' : type"
      :size="size === 'x-small' ? '' : size"
      :disabled="disabled"
      :loading="loading"
      :plain="plain"
      :text="text"
      :link="link"
      :soft="soft || undefined"
      :tag="to ? RouterLink : tag"
      :title="title"
      :aria-label="ariaLabel"
      :aria-expanded="ariaExpanded"
      :aria-controls="ariaControls"
      :aria-haspopup="ariaHaspopup"
      :aria-busy="ariaBusy"
      @click="emit('click', $event)"
  >
    <slot/>
  </ElButton>
</template>

<script setup>
import {RouterLink} from 'vue-router';
import {computed} from 'vue';
import {ElButton} from 'element-plus';
import {useDataAttrs} from './fieldProps';

defineOptions({name: 'FctButton', inheritAttrs: false});

const props = defineProps({
  type: {type: String, default: '', validator: (value) => ['', 'default', 'primary', 'success', 'warning', 'danger', 'info'].includes(value)},
  size: {type: String, default: '', validator: (value) => ['', 'default', 'large', 'small', 'x-small'].includes(value)},
  disabled: {type: Boolean, default: false},
  loading: {type: Boolean, default: false},
  plain: {type: Boolean, default: false},
  text: {type: Boolean, default: false},
  link: {type: Boolean, default: false},
  // Core's lighter button style ([soft] in button.scss).
  soft: {type: Boolean, default: false},
  tag: {type: String, default: 'button', validator: (value) => ['button', 'a'].includes(value)},
  // A route: the button renders as a router link.
  to: {type: [String, Object], default: undefined},
  href: {type: String, default: undefined},
  target: {type: String, default: undefined},
  rel: {type: String, default: undefined},
  id: {type: String, default: undefined},
  title: {type: String, default: undefined},
  ariaLabel: {type: String, default: undefined},
  ariaExpanded: {type: [Boolean, String], default: undefined},
  ariaControls: {type: String, default: undefined},
  ariaHaspopup: {type: [Boolean, String], default: undefined},
  ariaBusy: {type: [Boolean, String], default: undefined},
});

const emit = defineEmits(['click']);
const dataAttrs = useDataAttrs();

// Only set link attributes: an undefined href would override the href RouterLink builds from `to`.
const linkAttrs = computed(() => Object.fromEntries(
    Object.entries({to: props.to, href: props.href, target: props.target, rel: props.rel}).filter(([, value]) => value !== undefined)
));
</script>
