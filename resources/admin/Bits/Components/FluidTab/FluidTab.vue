<template>
  <div :class="`fct-fluid-tab ${hasIcon ? 'is-icon' : ''} ${small ? 'small' : ''}`" ref="tab">
    <div class="fct-fluid-tab-active-bar" :style="barStyle"></div>
    <slot/>
  </div>
</template>

<script setup>
import {ref, computed, provide, onMounted, nextTick} from 'vue';

const props = defineProps({
  activeIndex: null,
  hasIcon: {
    type: Boolean,
    default: false,
  },
  small: {
    type: Boolean,
    default: false,
  }
})

const tab = ref(null);
const barLeft = ref(0);
const barTop = ref(0);
const barWidth = ref(0);
const barHeight = ref(0);
const activeEl = ref(null);

const barStyle = computed(() => ({
  left: `${barLeft.value}px`,
  top: `${barTop.value}px`,
  width: `${barWidth.value}px`,
  height: `${barHeight.value}px`,
}));

const setActiveItem = (el) => {
  if (!tab.value || !el) return;

  const tabRect = tab.value.getBoundingClientRect();
  const {left, width, height, top} = el.getBoundingClientRect();
  barLeft.value = left - tabRect.left;
  barTop.value = top - tabRect.top;
  barWidth.value = width;
  barHeight.value = height;
  activeEl.value = el;
}

const setActiveByIndex = (index) => {
  if (!tab.value) return;

  const items = tab.value.children;
  if (items[index]) {
    setActiveItem(items[index])
  }
}

const setActiveByActiveClass = () => {
  nextTick(() => {
    if (!tab.value) return;
    
    const items = tab.value.children;
    const activeItem = Array.from(items).find(item => item.classList.contains('active'));
    if (activeItem) {
      setActiveItem(activeItem);
    }
  })
}


const isActiveItem = (el) => {
  return activeEl.value === el;
}

defineExpose({
  setActiveByActiveClass,
  setActiveByIndex
})

provide('setActiveItem', setActiveItem);
provide('isActiveItem', isActiveItem);

window.addEventListener('resize', () => {
  if (activeEl.value) {
    setActiveItem(activeEl.value);
  }
});

onMounted(() => {
  nextTick(() => {
    if (props.activeIndex) {
      setActiveByIndex(props.activeIndex - 1)
    } else {
      setActiveByActiveClass()
    }
  });
});
</script>
