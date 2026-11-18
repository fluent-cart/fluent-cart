<template>
  <ElDropdown
      :class="$attrs.class"
      :style="$attrs.style"
      :trigger="trigger"
      :placement="placement"
      :disabled="disabled"
      :hide-on-click="hideOnClick"
      popper-class="fct-dropdown"
      @command="onCommand"
      @visible-change="emit('visible-change', $event)"
  >
    <slot/>
    <template #dropdown>
      <ElDropdownMenu>
        <ElDropdownItem
            v-for="(item, index) in items"
            :key="index"
            :command="index"
            :disabled="item.disabled"
            :divided="item.divided"
            :class="[item.class, {'item-destructive': item.danger, 'item-link': item.href}]"
        >
          <slot v-if="$slots.item" name="item" :item="item"/>
          <a v-else-if="item.href" :href="item.href" :target="item.target" :rel="item.target === '_blank' ? 'noopener noreferrer' : undefined">
            <DynamicIcon v-if="item.icon" :name="item.icon"/>
            {{ item.label }}
          </a>
          <template v-else>
            <DynamicIcon v-if="item.icon" :name="item.icon"/>
            {{ item.label }}
          </template>
        </ElDropdownItem>
      </ElDropdownMenu>
    </template>
  </ElDropdown>
</template>

<script setup>
import {ElDropdown, ElDropdownItem, ElDropdownMenu} from 'element-plus';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';

defineOptions({name: 'FctDropdown', inheritAttrs: false});

const props = defineProps({
  // [{command, label, icon, disabled, danger, divided, href, target, class}]; the picked item's command comes back
  // on `command`. The #item slot ({item}) replaces an item's content.
  items: {type: Array, default: () => []},
  trigger: {type: String, default: 'hover', validator: (value) => ['hover', 'click'].includes(value)},
  placement: {type: String, default: 'bottom', validator: (value) => ['bottom', 'bottom-start', 'bottom-end', 'top', 'top-start', 'top-end'].includes(value)},
  disabled: {type: Boolean, default: false},
  hideOnClick: {type: Boolean, default: true},
});

const emit = defineEmits(['command', 'visible-change']);

// Element Plus hands back the item's position. An item without a command sends nothing,
// not Element Plus's default empty object.
const onCommand = (index) => {
  const item = props.items[index];
  if (item && item.command !== undefined) {
    emit('command', item.command);
  }
};
</script>
